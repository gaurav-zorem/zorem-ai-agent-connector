<?php
/**
 * How each Brain service is built.
 *
 * @package ZoremAgentBrain
 */

declare( strict_types=1 );

namespace ZoremAgentBrain\V1;

defined( 'ABSPATH' ) || exit;

use ZoremAgentBrain\V1\Admin\KnowledgeSummary;
use ZoremAgentBrain\V1\Admin\ScreenMount;
use ZoremAgentBrain\V1\Ai\ConsentLedger;
use ZoremAgentBrain\V1\Ai\CredentialStore;
use ZoremAgentBrain\V1\Ai\ModelGateway;
use ZoremAgentBrain\V1\Ai\ProviderResolver;
use ZoremAgentBrain\V1\Curated\CuratedIngestor;
use ZoremAgentBrain\V1\Curated\CuratedSync;
use ZoremAgentBrain\V1\Curated\FixtureCuratedKbAdapter;
use ZoremAgentBrain\V1\Curated\RegistryExporter;
use ZoremAgentBrain\V1\Curated\RegistryParser;
use ZoremAgentBrain\V1\Enrichment\PromptBuilder;
use ZoremAgentBrain\V1\Extraction\AbilityOriginRecorder;
use ZoremAgentBrain\V1\Extraction\AbilityPluginMap;
use ZoremAgentBrain\V1\Extraction\AbilityRisk;
use ZoremAgentBrain\V1\Extraction\Extractors\AbilityExtractor;
use ZoremAgentBrain\V1\Extraction\Extractors\CptExtractor;
use ZoremAgentBrain\V1\Extraction\Extractors\HeaderExtractor;
use ZoremAgentBrain\V1\Extraction\Extractors\HookExtractor;
use ZoremAgentBrain\V1\Extraction\Extractors\ReadmeExtractor;
use ZoremAgentBrain\V1\Extraction\Extractors\RestRouteExtractor;
use ZoremAgentBrain\V1\Extraction\Extractors\SettingsExtractor;
use ZoremAgentBrain\V1\Extraction\Extractors\ShortcodeExtractor;
use ZoremAgentBrain\V1\Extraction\PluginAttributionResolver;
use ZoremAgentBrain\V1\Extraction\PluginLocator;
use ZoremAgentBrain\V1\Extraction\TokenScanner;
use ZoremAgentBrain\V1\Gate\KnowledgeGate;
use ZoremAgentBrain\V1\Ingestion\CuratedCoverage;
use ZoremAgentBrain\V1\Ingestion\ExtractionLock;
use ZoremAgentBrain\V1\Ingestion\ExtractionQueue;
use ZoremAgentBrain\V1\Ingestion\FullRebuild;
use ZoremAgentBrain\V1\Ingestion\IngestionOrchestrator;
use ZoremAgentBrain\V1\Ingestion\IngestionScheduler;
use ZoremAgentBrain\V1\Ingestion\PluginChecksum;
use ZoremAgentBrain\V1\Scope\ReaderRegistry;
use ZoremAgentBrain\V1\Scope\ReaderScopeResolver;
use ZoremAgentBrain\V1\Scope\ScopeRepository;
use ZoremAgentBrain\V1\Sources\SourceRepository;
use ZoremAgentBrain\V1\Sources\WooCommerceSource;
use ZoremAgentBrain\V1\Sources\WpContentSource;
use ZoremAgentBrain\V1\Store\KnowledgeRepository;
use ZoremAgentBrain\V1\Store\Precedence;

/**
 * The kit's service table: class name => factory.
 *
 * Every factory receives a resolver for its dependencies, so each service is
 * built once per request and shared — both hosts see the same repository
 * cache, the same extraction lock and the same ability-origin record. The
 * table is code, not a filter: these services are authorisation inputs
 * (scope, attribution), and a hook that could swap one would be a hook that
 * widens what an agent may read.
 */
final class Services {

	/**
	 * Factories.
	 *
	 * @return array<string, callable(callable(string): object): object>
	 */
	public static function factories(): array {
		return array(
			KnowledgeRepository::class       => static fn () => new KnowledgeRepository(),
			ScopeRepository::class           => static fn () => new ScopeRepository(),
			ReaderRegistry::class            => static fn () => new ReaderRegistry(),
			Settings::class                  => static fn () => new Settings(),
			Precedence::class                => static fn ( callable $get ) => new Precedence( $get( KnowledgeRepository::class ) ),
			ReaderScopeResolver::class       => static fn ( callable $get ) => new ReaderScopeResolver( $get( ScopeRepository::class ) ),
			KnowledgeGate::class             => static fn ( callable $get ) => new KnowledgeGate( $get( ReaderScopeResolver::class ), $get( KnowledgeRepository::class ) ),

			// Extraction.
			PluginLocator::class             => static fn () => new PluginLocator(),
			TokenScanner::class              => static fn () => new TokenScanner(),
			AbilityOriginRecorder::class     => static fn () => new AbilityOriginRecorder(),
			AbilityRisk::class               => static fn () => new AbilityRisk(),
			PluginAttributionResolver::class => static fn ( callable $get ) => new PluginAttributionResolver( $get( PluginLocator::class ), $get( TokenScanner::class ), $get( AbilityOriginRecorder::class ) ),
			AbilityPluginMap::class          => static fn ( callable $get ) => new AbilityPluginMap( $get( AbilityOriginRecorder::class ), $get( PluginLocator::class ) ),

			// Ingestion.
			ExtractionLock::class            => static fn () => new ExtractionLock(),
			PluginChecksum::class            => static fn () => new PluginChecksum(),
			ExtractionQueue::class           => static fn ( callable $get ) => new ExtractionQueue( $get( PluginLocator::class ) ),
			CuratedCoverage::class           => static fn ( callable $get ) => new CuratedCoverage( $get( Settings::class ) ),
			IngestionOrchestrator::class     => static fn ( callable $get ) => new IngestionOrchestrator(
				$get( PluginLocator::class ),
				$get( PluginAttributionResolver::class ),
				$get( KnowledgeRepository::class ),
				$get( ExtractionQueue::class ),
				$get( ExtractionLock::class ),
				$get( CuratedCoverage::class ),
				$get( PluginChecksum::class ),
				$get( Settings::class ),
				$get( AbilityOriginRecorder::class ),
				self::extractors( $get )
			),
			IngestionScheduler::class        => static fn ( callable $get ) => new IngestionScheduler( $get( IngestionOrchestrator::class ), $get( PluginLocator::class ) ),

			// Sources.
			WpContentSource::class           => static fn ( callable $get ) => new WpContentSource( $get( KnowledgeRepository::class ) ),
			WooCommerceSource::class         => static fn ( callable $get ) => new WooCommerceSource( $get( KnowledgeRepository::class ), $get( WpContentSource::class ) ),
			SourceRepository::class          => static fn () => new SourceRepository(),

			// The one source that leaves the server; off until the merchant turns it on.

			// Curated registry, bundled: nothing leaves the server.
			RegistryParser::class            => static fn () => new RegistryParser(),
			FixtureCuratedKbAdapter::class   => static fn ( callable $get ) => new FixtureCuratedKbAdapter( self::registry_dir(), 'zorem-bundled', $get( RegistryParser::class ) ),
			CuratedIngestor::class           => static fn ( callable $get ) => new CuratedIngestor( $get( KnowledgeRepository::class ), $get( Precedence::class ) ),
			CuratedSync::class               => static fn ( callable $get ) => new CuratedSync( $get( FixtureCuratedKbAdapter::class ), $get( CuratedIngestor::class ), $get( PluginLocator::class ), $get( Settings::class ) ),
			RegistryExporter::class          => static fn ( callable $get ) => new RegistryExporter( $get( KnowledgeRepository::class ) ),

			// The site's AI provider: one key, one acknowledgement, one gateway for both plugins.
			ConsentLedger::class             => static fn () => new ConsentLedger(),
			CredentialStore::class           => static fn ( callable $get ) => new CredentialStore( $get( ConsentLedger::class ) ),
			ProviderResolver::class          => static fn ( callable $get ) => new ProviderResolver( $get( CredentialStore::class ) ),
			ModelGateway::class              => static fn ( callable $get ) => new ModelGateway( $get( ProviderResolver::class ), $get( CredentialStore::class ), $get( ConsentLedger::class ) ),

			// The shared prompt fence the assistants use.
			PromptBuilder::class             => static fn () => new PromptBuilder(),

			// Where the screens appear (D-10).
			ScreenMount::class               => static fn () => new ScreenMount(),
			KnowledgeSummary::class          => static fn ( callable $get ) => new KnowledgeSummary( $get( KnowledgeRepository::class ), $get( PluginLocator::class ) ),

			// Read everything again.
			FullRebuild::class               => static fn ( callable $get ) => new FullRebuild(
				$get( IngestionOrchestrator::class ),
				$get( WooCommerceSource::class ),
				$get( CuratedSync::class ),
				$get( WpContentSource::class ),
				$get( KnowledgeRepository::class ),
				$get( Precedence::class )
			),
		);
	}

	/**
	 * The bundled curated registry, from whichever copy of the kit loaded.
	 */
	public static function registry_dir(): string {
		$root = defined( 'ZOREM_AGENT_BRAIN_DIR' ) ? (string) ZOREM_AGENT_BRAIN_DIR : trailingslashit( dirname( __DIR__, 2 ) );

		return $root . 'registry/1';
	}

	/**
	 * The extractors, in the order the orchestrator runs them.
	 *
	 * Order is part of the resume cursor: a tick that dies inside extractor five
	 * resumes at five, which only means anything if the list is stable. New
	 * extractors go on the end.
	 *
	 * @param callable(string): object $get Service resolver.
	 * @return array<int, \ZoremAgentBrain\V1\Extraction\ExtractorInterface>
	 */
	private static function extractors( callable $get ): array {
		return array(
			new HeaderExtractor(),
			new ReadmeExtractor(),
			new AbilityExtractor( $get( AbilityRisk::class ) ),
			new ShortcodeExtractor(),
			new CptExtractor(),
			new RestRouteExtractor(),
			new SettingsExtractor(),
			new HookExtractor(),
		);
	}
}
