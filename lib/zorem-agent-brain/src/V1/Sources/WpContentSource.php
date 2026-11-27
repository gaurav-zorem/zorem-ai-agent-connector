<?php
/**
 * The pages a merchant chose to let the assistant read.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1\Sources;

use ZoremAgentBrain\V1\Extraction\Provenance;
use ZoremAgentBrain\V1\Store\KnowledgeEntry;
use ZoremAgentBrain\V1\Store\KnowledgeRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Turns selected pages into knowledge the storefront assistant can answer from.
 *
 * This is the content path behind the product card's *"knows your policies from
 * the Website Brain"*. Until it existed, the shopping assistant held the two
 * knowledge abilities and a scope covering the `manual` and `faq` sections, and
 * there was nothing in either — grants and plumbing with no water.
 *
 * ## Selection, never a crawl
 *
 * A merchant ticks specific pages. Nothing is ingested until they do, and
 * nothing is ingested that they did not tick.
 *
 * That is the constraint that made this acceptable at all. A blanket crawl of a
 * WordPress site puts draft pages, internal notes, staff-only content, half-
 * written landing pages and the "test page please ignore" from 2019 into the
 * reach of an assistant that answers anonymous strangers on a public storefront.
 * No default is safe enough to guess, and "we only crawl published pages" is not
 * a defence — published does not mean public-facing, and it certainly does not
 * mean the merchant wants a chatbot quoting it.
 *
 * ## Public tier, deliberately
 *
 * Entries land in `manual` (policy prose) or `faq`, both **public tier** — which
 * is what lets a public reader such as the storefront read them, given a rule
 * for the section, since it is barred from the internal tier (`Reader::reads_internal()`).
 * Entries carry no plugin slug, so nothing reads them by derivation alone. That is not a
 * loophole: the merchant chose these pages precisely so a shopper could be told
 * what is in them, and they are already published on the site.
 *
 * ## Author prose, and treated as such
 *
 * Provenance is `author_prose`, never `token_derived`. A returns policy is
 * something a human typed, not a fact read out of code, so it reaches the model
 * fenced and labelled untrusted like any other human text — the same machinery
 * the plugin readmes go through. A page containing "ignore previous
 * instructions" is quoted, not obeyed.
 *
 * Shortcodes are stripped rather than rendered. A policy page containing
 * `[products]` must not run a query on its way into the knowledge base.
 *
 * ## Re-ingestion
 *
 * The entry carries a checksum of the page's content. `save_post` re-ingests a
 * selected page, and an unchanged page is left alone — so editing a returns
 * policy reaches the assistant without a manual step, and re-saving a page for
 * an unrelated reason does not churn the row.
 */
final class WpContentSource {

	/**
	 * Option holding the selected post ids.
	 */
	public const OPTION = 'zorem_agent_brain_policy_pages';

	/**
	 * Whether every published post is read, standing.
	 *
	 * Separate from the selection rather than expressed as one, because it is a
	 * different kind of statement. The selection names pages; this names a rule,
	 * and a rule has to keep applying — a post published next month is covered
	 * by it without anybody revisiting this screen. Storing it as "tick all the
	 * boxes now" would cover today's posts and silently miss every later one.
	 */
	public const OPTION_ALL_POSTS = 'zorem_agent_brain_all_posts';

	/**
	 * How many posts the standing rule will read.
	 *
	 * A bound, and a stated one. A shop with four thousand posts would
	 * otherwise turn one checkbox into four thousand knowledge entries, most of
	 * a gigabyte of extracted text, and a retrieval set the agent has to search
	 * through to answer "where is my order". The projector caps what reaches an
	 * agent at 200 anyway, so reading past that produces rows nothing can read.
	 */
	public const MAX_ALL_POSTS = 200;

	/**
	 * Post types a merchant may select from.
	 *
	 * Pages and posts. Not products — those are read live by the catalogue
	 * abilities, and a product description duplicated into knowledge would go
	 * stale the moment somebody edited it.
	 *
	 * @return array<int, string>
	 */
	public static function selectable_types(): array {
		/**
		 * Filters which post types may be selected as store policy.
		 *
		 * @param array<int, string> $types Post type names.
		 */
		return (array) apply_filters( 'zorem_agent_brain_policy_post_types', array( 'page', 'post' ) );
	}

	/**
	 * Longest content stored per page.
	 *
	 * A policy page is a page. One that runs past this is a document, and the
	 * assistant's retrieval budget would trim it anyway — better to trim once,
	 * here, visibly, than every turn.
	 */
	private const MAX_CONTENT = 8000;

	/**
	 * Entry storage.
	 *
	 * @var KnowledgeRepository
	 */
	private KnowledgeRepository $repository;

	/**
	 * Constructor.
	 *
	 * @param KnowledgeRepository $repository Entry storage.
	 */
	public function __construct( KnowledgeRepository $repository ) {
		$this->repository = $repository;
	}

	/**
	 * Hook re-ingestion.
	 */
	public function register(): void {
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 2 );
		add_action( 'trashed_post', array( $this, 'on_trashed_post' ) );
	}

	// -----------------------------------------------------------------
	// Selection.
	// -----------------------------------------------------------------

	/**
	 * The post ids currently selected.
	 *
	 * @return array<int, int>
	 */
	public function selected(): array {
		$stored = get_option( self::OPTION, array() );

		return array_values( array_unique( array_map( 'intval', (array) $stored ) ) );
	}

	/**
	 * Replace the selection, ingesting what is new and removing what is gone.
	 *
	 * Returns what actually happened rather than just succeeding, because a
	 * merchant who ticks five pages and gets four entries needs to know which
	 * one did not make it and why.
	 *
	 * @param array<int, int> $post_ids Post ids to select.
	 * @return array<string, mixed>
	 */
	public function select( array $post_ids ): array {
		$wanted   = array();
		$rejected = array();

		foreach ( array_map( 'intval', $post_ids ) as $id ) {
			$reason = $this->cannot_ingest( $id );

			if ( '' !== $reason ) {
				$rejected[] = array(
					'id'     => $id,
					'reason' => $reason,
				);

				continue;
			}

			$wanted[] = $id;
		}

		$wanted  = array_values( array_unique( $wanted ) );
		$removed = array_diff( $this->selected(), $wanted );

		update_option( self::OPTION, $wanted, false );

		foreach ( $removed as $id ) {
			$this->forget( (int) $id );
		}

		$ingested = array();

		foreach ( $wanted as $id ) {
			if ( $this->ingest( (int) $id ) ) {
				$ingested[] = (int) $id;
			}
		}

		return array(
			'selected' => $wanted,
			'ingested' => $ingested,
			'removed'  => array_values( array_map( 'intval', $removed ) ),
			'rejected' => $rejected,
		);
	}

	/**
	 * Why a post cannot be ingested, or an empty string when it can.
	 *
	 * @param int $post_id Post id.
	 */
	private function cannot_ingest( int $post_id ): string {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post ) {
			return __( 'That page no longer exists.', 'zorem-ai-agent-connector' );
		}

		if ( ! in_array( $post->post_type, self::selectable_types(), true ) ) {
			return __( 'Only pages and posts can be used as store policy.', 'zorem-ai-agent-connector' );
		}

		if ( 'publish' !== $post->post_status ) {
			/*
			 * Drafts and private pages are refused, not silently skipped. A
			 * merchant who ticks a draft and sees nothing happen will tick it
			 * again; one who is told why will publish it.
			 */
			return __( 'Only published pages can be used. This one is not published.', 'zorem-ai-agent-connector' );
		}

		if ( ! empty( $post->post_password ) ) {
			return __( 'This page is password-protected, so the assistant will not read it.', 'zorem-ai-agent-connector' );
		}

		if ( '' === trim( $this->plain_content( $post ) ) ) {
			return __( 'This page has no text the assistant could use.', 'zorem-ai-agent-connector' );
		}

		return '';
	}

	/**
	 * Whether the "every post" rule may read a post: written by someone who may edit others' posts.
	 *
	 * The rule reads posts nobody chose one by one, and stores them approved,
	 * where shopper-facing assistants can read them. An Author or Contributor
	 * publishing a post must not put words in the shop assistant's mouth. A
	 * page an administrator selects by hand is read whoever wrote it.
	 *
	 * @param int $post_id Post id.
	 */
	private function rule_may_read( int $post_id ): bool {
		$post = get_post( $post_id );

		return $post instanceof \WP_Post && user_can( (int) $post->post_author, 'edit_others_posts' );
	}

	/**
	 * Ingest one selected page.
	 *
	 * @param int $post_id Post id.
	 * @return bool Whether an entry was written.
	 */
	public function ingest( int $post_id ): bool {
		if ( '' !== $this->cannot_ingest( $post_id ) ) {
			return false;
		}

		$post    = get_post( $post_id );
		$content = $this->plain_content( $post );
		$key     = $this->entry_key( $post_id );

		/*
		 * `md5`, not `sha256`, and not by preference. The `checksum` column is
		 * `char(32)`, so a 64-character digest fails the insert outright — which
		 * is exactly what the first version of this class did, silently, because
		 * `$wpdb->insert()` returns false and the failure surfaced as "ingested:
		 * []" rather than as an error about column width.
		 *
		 * Every other producer in the codebase already used `md5` and none of
		 * them documented why. This is a change-detector, not a security
		 * primitive, so the width is the constraint that matters.
		 */
		$checksum = md5( $post->post_title . '|' . $content );

		$existing = $this->repository->find( $key );

		if ( $existing instanceof KnowledgeEntry && $existing->get_checksum() === $checksum ) {
			// Unchanged. Re-saving a page for an unrelated reason must not churn.
			return false;
		}

		$result = $this->repository->upsert(
			array(
				'entry_key'    => $key,
				'source_type'  => KnowledgeEntry::SOURCE_WP_CONTENT,
				'source_ref'   => (string) $post_id,
				'plugin_slug'  => null,
				'section'      => $this->section_for( $post ),
				'title'        => $post->post_title,
				'summary'      => $this->summarise( $content ),
				'content'      => $content,
				'checksum'     => $checksum,
				'trust_status' => KnowledgeEntry::TRUST_APPROVED,
				'meta'         => array(

					/*
					 * `author_prose`, always. A returns policy is something a
					 * human typed, so it reaches the model fenced and labelled
					 * untrusted — the same treatment plugin readmes get. A page
					 * containing "ignore previous instructions" is quoted, not
					 * obeyed.
					 */
					'provenance' => array( 'content' => Provenance::AUTHOR_PROSE ),
					'wp_content' => array(
						'post_id'       => $post_id,
						'post_type'     => $post->post_type,
						'permalink'     => (string) get_permalink( $post_id ),
						'ingested_at'   => current_time( 'mysql', true ),
						'selected_by'   => get_current_user_id(),
						'was_truncated' => mb_strlen( $this->raw_content( $post ) ) > self::MAX_CONTENT,
					),
				),
			)
		);

		return ! is_wp_error( $result );
	}

	// -----------------------------------------------------------------
	// Every post, standing.
	// -----------------------------------------------------------------

	/**
	 * Whether every published post is read.
	 */
	public function all_posts(): bool {
		return (bool) get_option( self::OPTION_ALL_POSTS, false );
	}

	/**
	 * Published post ids, newest first, bounded.
	 *
	 * Newest first because if the bound is going to drop something, the thing
	 * to drop is the oldest post rather than whichever the database returned
	 * last. A three-year-old announcement is the least useful answer this rule
	 * can produce.
	 *
	 * @return array<int, int>
	 */
	public function published_post_ids(): array {
		return array_map(
			'intval',
			(array) get_posts(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'fields'         => 'ids',
					// phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Bounded deliberately; the bound is reported to the merchant.
					'posts_per_page' => self::MAX_ALL_POSTS,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			)
		);
	}

	/**
	 * How many published posts there are, whatever the bound.
	 *
	 * Counted rather than inferred from the bounded list, so the screen can say
	 * "200 of 412 read" instead of implying it read them all.
	 */
	public function published_post_count(): int {
		$counts = wp_count_posts( 'post' );

		return (int) ( $counts->publish ?? 0 );
	}

	/**
	 * Turn the standing rule on or off, and make it true immediately.
	 *
	 * @param bool $on Whether to read every published post.
	 * @return array<string, mixed> What happened.
	 */
	public function set_all_posts( bool $on ): array {
		update_option( self::OPTION_ALL_POSTS, $on, false );

		$ids      = $this->published_post_ids();
		$ingested = 0;
		$removed  = 0;

		foreach ( $ids as $id ) {
			if ( $on ) {
				if ( '' === $this->cannot_ingest( $id ) && $this->rule_may_read( $id ) && $this->ingest( $id ) ) {
					++$ingested;
				}

				continue;
			}

			/*
			 * Off again drops what the rule brought in — but never a post the
			 * merchant had ticked by hand. Their choice predates the rule and
			 * outlives it; a switch that quietly deletes something somebody
			 * selected deliberately is a switch nobody can trust twice.
			 */
			if ( ! in_array( $id, $this->selected(), true ) ) {
				$this->forget( $id );
				++$removed;
			}
		}

		return array(
			'all_posts' => $on,
			'ingested'  => $ingested,
			'removed'   => $removed,
			'total'     => $this->published_post_count(),
			'read'      => count( $ids ),
		);
	}

	/**
	 * Remove the entry for a page that is no longer selected.
	 *
	 * @param int $post_id Post id.
	 */
	public function forget( int $post_id ): void {
		$entry = $this->repository->find( $this->entry_key( $post_id ) );

		if ( $entry instanceof KnowledgeEntry && $entry->get_id() > 0 ) {
			$this->repository->delete( $entry->get_id() );
		}
	}

	/**
	 * Re-ingest a selected page when it is saved.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    The post.
	 */
	public function on_save_post( $post_id, $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		/*
		 * Selected by hand, or covered by the standing rule. The second is what
		 * makes the rule a rule: a post published after the box was ticked is
		 * read on the day it goes live rather than whenever somebody next
		 * visits this screen.
		 */
		$selected = in_array( (int) $post_id, $this->selected(), true );
		$by_rule  = ! $selected && $this->all_posts() && 'post' === get_post_type( $post_id );

		if ( ! $selected && ! $by_rule ) {
			return;
		}

		unset( $post );

		// The rule reads only trusted authors' posts (see rule_may_read()); a hand-picked page is read regardless.
		if ( $by_rule && ! $this->rule_may_read( (int) $post_id ) ) {
			$this->forget( (int) $post_id );

			return;
		}

		/*
		 * A selected page that stops being publishable loses its entry rather
		 * than keeping a stale one. Unpublishing a returns policy and having the
		 * assistant keep quoting it is the failure this avoids.
		 */
		if ( '' !== $this->cannot_ingest( (int) $post_id ) ) {
			$this->forget( (int) $post_id );

			return;
		}

		$this->ingest( (int) $post_id );
	}

	/**
	 * Drop a selected page's entry when it is trashed.
	 *
	 * @param int $post_id Post id.
	 */
	public function on_trashed_post( $post_id ): void {
		$covered = in_array( (int) $post_id, $this->selected(), true )
			|| ( $this->all_posts() && 'post' === get_post_type( $post_id ) );

		if ( $covered ) {
			$this->forget( (int) $post_id );
		}
	}

	// -----------------------------------------------------------------
	// Shaping.
	// -----------------------------------------------------------------

	/**
	 * The entry key for a post.
	 *
	 * @param int $post_id Post id.
	 */
	public function entry_key( int $post_id ): string {
		return 'wp_content:post:' . $post_id;
	}

	/**
	 * Which section a page belongs in.
	 *
	 * `faq` when the page looks like one, `manual` otherwise. Both are public
	 * tier, so the choice affects how the assistant frames an answer rather than
	 * whether it may read it.
	 *
	 * @param \WP_Post $post The post.
	 */
	private function section_for( \WP_Post $post ): string {
		$looks_like_faq = (bool) preg_match( '/\bfaq\b|frequently asked|questions/i', $post->post_title );

		/**
		 * Filters the knowledge section a selected page is stored in.
		 *
		 * @param string   $section One of the public-tier section names.
		 * @param \WP_Post $post    The post.
		 */
		$section = (string) apply_filters(
			'zorem_agent_brain_policy_section',
			$looks_like_faq ? KnowledgeEntry::SECTION_FAQ : KnowledgeEntry::SECTION_MANUAL,
			$post
		);

		// Never let a filter move a shopper-facing page into the internal tier.
		return \ZoremAgentBrain\V1\Scope\SectionTiers::is_public( $section )
			? $section
			: KnowledgeEntry::SECTION_MANUAL;
	}

	/**
	 * The page's text, flattened and bounded.
	 *
	 * @param \WP_Post $post The post.
	 */
	private function plain_content( \WP_Post $post ): string {
		$content = $this->raw_content( $post );

		if ( mb_strlen( $content ) <= self::MAX_CONTENT ) {
			return $content;
		}

		return mb_substr( $content, 0, self::MAX_CONTENT ) . "\n\n"
			. __( '[This page was longer than the assistant stores. The rest is on the page itself.]', 'zorem-ai-agent-connector' );
	}

	/**
	 * The page's text with markup, shortcodes and blocks removed.
	 *
	 * Shortcodes are **stripped, not rendered**. A policy page containing
	 * `[products]` must not run a query on its way into the knowledge base, and
	 * `do_shortcode()` here would do exactly that — with whatever side effects
	 * the shortcode's author felt like having.
	 *
	 * @param \WP_Post $post The post.
	 */
	private function raw_content( \WP_Post $post ): string {
		$content = (string) $post->post_content;

		if ( function_exists( 'parse_blocks' ) && has_blocks( $content ) ) {
			$content = $this->static_block_html( parse_blocks( $content ) );
		}

		$content = strip_shortcodes( $content );

		/*
		 * Script and style *contents* go, not just their tags.
		 * `wp_strip_all_tags()` removes `<script>` and leaves `alert(1)` behind,
		 * because that is what stripping tags means — and the result is a
		 * knowledge entry containing a line of JavaScript as prose.
		 *
		 * It could never execute: the entry reaches a model fenced, and the
		 * widget renders every answer with `textContent`. But it is noise a
		 * shopper might be read back, and it *looks* like a leak to anyone
		 * reading the entry, which is its own cost.
		 */
		$content = (string) preg_replace( '#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $content );

		/*
		 * Block boundaries become newlines before the tags go, because
		 * `</p><p>` leaves no whitespace behind and the result reads
		 * "…full refund.Subscriptions can be cancelled…". Harmless to a model,
		 * unreadable to the merchant checking what their assistant knows, and
		 * invisible to a word-boundary search for "refund Subscriptions".
		 */
		$content = (string) preg_replace(
			'#</(?:p|div|li|h[1-6]|tr|blockquote|section|article)\s*>|<br\s*/?>#i',
			"\n",
			$content
		);

		// Not `$remove_breaks = true`: the newlines above are the paragraph structure.
		$content = wp_strip_all_tags( $content );
		$content = str_replace( array( "\r\n", "\r" ), "\n", $content );

		/*
		 * Entities decoded before whitespace is collapsed, so `&nbsp;` becomes a
		 * space rather than surviving as one. Doing it the other way round left
		 * "30&nbsp;days" as "30\u{00A0}days": correct to read, invisible to a
		 * search for "30 days", and invisible to `preg_replace('/\s+/')` too,
		 * which does not match U+00A0 without the `u` modifier.
		 */
		$content = html_entity_decode( $content, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$content = str_replace( array( "\u{00A0}", "\u{200B}", "\u{FEFF}" ), ' ', $content );

		$content = (string) preg_replace( "/\n{3,}/", "\n\n", $content );
		$content = (string) preg_replace( '/[ \t]+/', ' ', $content );

		return trim( $content );
	}

	/**
	 * The saved markup of a block tree, with dynamic blocks left out.
	 *
	 * Replaces `excerpt_remove_blocks()`, which skips any block whose inner
	 * blocks are not on its allow-list. Since WP 6.1 every list is a `core/list`
	 * of `core/list-item` children, and `core/list-item` is not on that list —
	 * so every bulleted list on a policy page vanished, taking "Final Sale items
	 * cannot be returned" with it, and FAQ accordions (`core/details`) went the
	 * same way. The assistant then answered from a page with its rules missing.
	 *
	 * Every block contributes the HTML it saved, and nothing is rendered. A
	 * purely dynamic block saves no markup, so it contributes nothing: its
	 * output is whatever its callback decides at render time, which is the same
	 * reason shortcodes are stripped rather than run. "Has a render callback" is
	 * not the test — `core/heading` and `core/list` register one and still save
	 * their full text, and filtering on `is_dynamic()` dropped every heading.
	 * A block hidden with `blockVisibility: false` is skipped, as core skips it.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 */
	private function static_block_html( array $blocks ): string {
		$html = '';

		foreach ( $blocks as $block ) {
			if ( false === ( $block['attrs']['metadata']['blockVisibility'] ?? null ) ) {
				continue;
			}

			$inner = (array) ( $block['innerBlocks'] ?? array() );
			$index = 0;

			// innerContent interleaves saved HTML with a null for each child block.
			foreach ( (array) ( $block['innerContent'] ?? array() ) as $chunk ) {
				if ( is_string( $chunk ) ) {
					$html .= $chunk;
				} elseif ( isset( $inner[ $index ] ) ) {
					$html .= $this->static_block_html( array( $inner[ $index ] ) );
					++$index;
				}
			}
		}

		return $html;
	}

	/**
	 * A one-line summary for search results.
	 *
	 * @param string $content Flattened content.
	 */
	private function summarise( string $content ): string {
		$first = trim( (string) preg_replace( '/\s+/', ' ', mb_substr( $content, 0, 400 ) ) );

		return mb_strlen( $first ) < mb_strlen( $content ) ? $first . '…' : $first;
	}
}
