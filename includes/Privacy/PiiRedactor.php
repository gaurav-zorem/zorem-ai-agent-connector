<?php
/**
 * Response PII redaction.
 *
 * @package AiAgentOs
 */

declare( strict_types=1 );

namespace AiAgentOs\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Masks customer personal data in ability responses.
 *
 * Why this exists: a per-ability allow-list cannot express "read orders but not
 * customer details", because those are fields inside a single ability rather
 * than separate abilities. The Fulfillment, Supplier and Shopping profiles all
 * promise exactly that boundary, so it has to be enforced somewhere. This is
 * that somewhere.
 *
 * It fails closed. For an agent that masks, every ability's result is masked —
 * not only the abilities whose payload we have read — because the ones we
 * have not read are exactly where a customer's details would slip through
 * (Subscriptions, a CRM, `core/get-user-info`, a WooCommerce ability added
 * later). An ability is left alone only when it is on the exemption filter.
 *
 * Three passes, at any depth, through arrays and objects alike:
 *
 *   1. Personal fields by name (`email`, `billing_phone`, `display_name`, …),
 *      including WooCommerce's `meta_data` pairs whose key names one.
 *   2. Email addresses anywhere in any string, through `redact_text()`.
 *   3. A `WP_Error`'s messages and data, the same way.
 *
 * Two properties it must preserve:
 *
 *   1. Structure. Keys are masked, never removed, so a client validating the
 *      response against the ability's output schema still sees the shape it
 *      expects.
 *
 *   2. Format validity. WooCommerce's orders-query output schema declares
 *      'format' => 'email' on the billing email field, and WP_Ability::execute()
 *      runs rest_validate_value_from_schema() on the result after the execute
 *      callback returns. A plain "[masked]" in an email field would fail that
 *      validation and turn a permitted call into an error — the redaction would
 *      break the ability it is protecting. So the mask is chosen to match the
 *      shape of the value being replaced, and only strings are replaced.
 */
final class PiiRedactor {

	/**
	 * The mask used for ordinary string values.
	 */
	public const MASK = '[masked]';

	/**
	 * A mask that still satisfies 'format' => 'email'.
	 */
	public const MASK_EMAIL = 'masked@masked.invalid';

	/**
	 * Input keys a masked agent may not search customers by.
	 *
	 * A count of matching orders answers "is this person a customer?" as well
	 * as the masked email would have.
	 */
	private const SEARCH_KEYS = array( 'email', 'billing_email', 'customer_email', 'phone', 'billing_phone', 'customer' );

	/**
	 * Which abilities and fields are in scope.
	 *
	 * @var CoveredAbilities
	 */
	private CoveredAbilities $covered;

	/**
	 * Constructor.
	 *
	 * @param CoveredAbilities $covered Which abilities and fields are in scope.
	 */
	public function __construct( CoveredAbilities $covered ) {
		$this->covered = $covered;
	}

	/**
	 * Mask personal data in free prose.
	 *
	 * Added in Phase 3, because the storefront assistant's output is a paragraph
	 * rather than a structured result and field masking has nothing to hold
	 * onto in a sentence.
	 *
	 * **It masks email addresses and nothing else, and that is a decision rather
	 * than a first draft.** Phone numbers, postcodes and street addresses cannot
	 * be recognised in shop prose without wrecking correct answers: a 13-digit
	 * EAN, an ISBN, a SKU and a model number all look like a phone number to a
	 * regular expression, and masking a product's own identifier is a worse
	 * outcome for a shopper than the leak it is guessing at.
	 *
	 * **The shop's own contact addresses survive.** An assistant that masks the
	 * support address on the returns page has broken a correct and useful answer
	 * in the name of privacy.
	 *
	 * @param string $text Free prose.
	 */
	public function redact_text( string $text ): string {
		if ( false === strpos( $text, '@' ) ) {
			return $text;
		}

		$keep = $this->public_addresses();

		return (string) preg_replace_callback(
			'/[\w.+-]+@[\w-]+(?:\.[\w-]+)+/i',
			static function ( array $found ) use ( $keep ): string {
				return in_array( strtolower( $found[0] ), $keep, true ) ? $found[0] : self::MASK_EMAIL;
			},
			$text
		);
	}

	/**
	 * Addresses that are meant to be public.
	 *
	 * @return array<int, string>
	 */
	private function public_addresses(): array {
		$addresses = array( (string) get_option( 'admin_email', '' ) );

		/**
		 * Filters the email addresses a public assistant may repeat.
		 *
		 * A shop with a dedicated support or returns address should add it here,
		 * or the assistant will mask it when quoting its own policy page.
		 *
		 * @param array<int, string> $addresses Addresses that are safe to show.
		 */
		$addresses = (array) apply_filters( 'ai_agent_os_public_email_addresses', $addresses );

		return array_values(
			array_filter(
				array_map( 'strtolower', array_map( 'strval', $addresses ) )
			)
		);
	}

	/**
	 * Mask every personal field in any data, whichever ability or route produced it.
	 *
	 * For the WooCommerce REST door, where a route's response is not an ability
	 * result and may carry an order's customer whatever the route is for.
	 *
	 * @param array<mixed> $data The data.
	 * @return array<mixed> The data, with personal fields masked.
	 */
	public function redact_fields( array $data ): array {
		return $this->walk( $data, $this->covered->fields(), true );
	}

	/**
	 * Redact an ability's result for an agent that masks.
	 *
	 * Every ability, unless the exemption filter names it.
	 *
	 * @param string $ability_name Ability that produced the result.
	 * @param mixed  $result       The result.
	 * @return mixed The result, with personal data masked.
	 */
	public function redact( string $ability_name, $result ) {
		if ( $this->covered->is_exempt( $ability_name ) ) {
			return $result;
		}

		// Address parts are masked anywhere in a covered result; elsewhere only under a customer or address key.
		return $this->redact_value( $result, $this->covered->is_covered( $ability_name ) );
	}

	/**
	 * Mask personal data in any value: arrays, objects, strings and errors.
	 *
	 * @param mixed $value      The value.
	 * @param bool  $in_address Whether address parts are masked from the top.
	 * @return mixed
	 */
	public function redact_value( $value, bool $in_address = false ) {
		$fields = $this->covered->fields();

		if ( $value instanceof \WP_Error ) {
			return $this->redact_error( $value, $fields );
		}

		if ( is_object( $value ) ) {
			// Walked as the client will receive it: JSON.
			$decoded = json_decode( (string) wp_json_encode( $value ), true );
			$value   = is_array( $decoded ) ? $decoded : array();
		}

		if ( is_array( $value ) ) {
			return $this->walk( $value, $fields, $in_address );
		}

		return is_string( $value ) ? $this->redact_text( $value ) : $value;
	}

	/**
	 * Remove customer search terms from a masked agent's input.
	 *
	 * Email and phone keys go, and a free-text `search` that is an email
	 * address or a phone number is dropped.
	 *
	 * @param mixed $input The call's input.
	 * @return mixed
	 */
	public function strip_customer_search( $input ) {
		if ( ! is_array( $input ) ) {
			return $input;
		}

		foreach ( self::SEARCH_KEYS as $key ) {
			unset( $input[ $key ] );
		}

		if ( isset( $input['search'] ) && is_string( $input['search'] ) && self::looks_like_contact( $input['search'] ) ) {
			unset( $input['search'] );
		}

		return $input;
	}

	/**
	 * Whether a search term is an email address or a phone number.
	 *
	 * @param string $term Search term.
	 */
	private static function looks_like_contact( string $term ): bool {
		if ( false !== strpos( $term, '@' ) ) {
			return true;
		}

		$digits = (string) preg_replace( '/\D+/', '', $term );

		return strlen( $digits ) >= 7 && (bool) preg_match( '/^[\d\s()+.\-]+$/', trim( $term ) );
	}

	/**
	 * A copy of an error with its messages and data masked.
	 *
	 * @param \WP_Error          $error  The error.
	 * @param array<int, string> $fields Field names to mask.
	 */
	private function redact_error( \WP_Error $error, array $fields ): \WP_Error {
		$masked = new \WP_Error();

		foreach ( $error->get_error_codes() as $code ) {
			$data = $error->get_error_data( $code );

			foreach ( $error->get_error_messages( $code ) as $message ) {
				$masked->add( $code, $this->redact_text( (string) $message ) );
			}

			if ( is_array( $data ) ) {
				$masked->add_data( $this->walk( $data, $fields, false ), $code );
			} elseif ( null !== $data ) {
				$masked->add_data( is_string( $data ) ? $this->redact_text( $data ) : $data, $code );
			}
		}

		return $masked;
	}

	/**
	 * Walk a payload, masking matching keys and email addresses at any depth.
	 *
	 * Address parts (`city`, `state`, `postcode`, …) are generic words, so
	 * outside a covered ability they are masked only under a key that names a
	 * customer or an address: masking a `state` that is an enum elsewhere
	 * would fail the ability's own output schema.
	 *
	 * @param array<mixed, mixed> $data       The payload.
	 * @param array<int, string>  $fields     Field names to mask.
	 * @param bool                $in_address Whether address parts are masked here.
	 * @return array<mixed, mixed>
	 */
	private function walk( array $data, array $fields, bool $in_address = true ): array {
		// A WooCommerce meta pair: {key: "_billing_phone", value: "…"}.
		$pair_masks = isset( $data['key'], $data['value'] ) && is_string( $data['key'] ) && $this->should_mask( $data['key'], $fields, true );

		foreach ( $data as $key => $value ) {
			if ( is_object( $value ) && ! $value instanceof \WP_Error ) {
				$decoded = json_decode( (string) wp_json_encode( $value ), true );
				$value   = is_array( $decoded ) ? $decoded : array();
			}

			if ( is_array( $value ) ) {
				$data[ $key ] = $this->walk( $value, $fields, $in_address || ( is_string( $key ) && (bool) preg_match( '/billing|shipping|address|customer|contact|note/i', $key ) ) );
				continue;
			}

			if ( ! is_string( $value ) || '' === $value ) {
				// Only strings are replaced, so a schema's type still holds. Nothing to hide in an empty one.
				continue;
			}

			if ( ( is_string( $key ) && $this->should_mask( $key, $fields, $in_address ) ) || ( $pair_masks && 'value' === $key ) ) {
				$data[ $key ] = $this->mask_for( $value );
				continue;
			}

			$data[ $key ] = $this->redact_text( $value );
		}

		return $data;
	}

	/**
	 * Whether a key names personal data.
	 *
	 * Matched without leading underscores (`_billing_phone`), exactly or as
	 * the end of a prefixed name (`shipping_phone`, `billing_first_name`).
	 *
	 * @param string             $key        The key.
	 * @param array<int, string> $fields     Field names to mask.
	 * @param bool               $in_address Whether bare address parts count.
	 */
	private function should_mask( string $key, array $fields, bool $in_address ): bool {
		$key = ltrim( strtolower( $key ), '_' );

		if ( in_array( $key, $fields, true ) ) {
			return $in_address || ! in_array( $key, $this->covered->address_fields(), true );
		}

		foreach ( $this->covered->field_suffixes() as $suffix ) {
			if ( strlen( $key ) > strlen( $suffix ) && substr( $key, -strlen( $suffix ) - 1 ) === '_' . $suffix ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Choose a mask that will still pass the ability's output schema.
	 *
	 * @param mixed $value The value being replaced.
	 * @return mixed
	 */
	private function mask_for( $value ) {
		if ( is_string( $value ) && filter_var( $value, FILTER_VALIDATE_EMAIL ) ) {
			$mask = self::MASK_EMAIL;
		} else {
			$mask = self::MASK;
		}

		/**
		 * Filters the value substituted for masked personal data.
		 *
		 * A replacement must remain valid against the ability's output schema:
		 * WordPress validates the result after the execute callback returns, so
		 * a mask that violates a declared format turns a permitted call into an
		 * error.
		 *
		 * @param mixed $mask  The mask that will be used.
		 * @param mixed $value The original value.
		 */
		return apply_filters( 'ai_agent_os_pii_mask_value', $mask, $value );
	}
}
