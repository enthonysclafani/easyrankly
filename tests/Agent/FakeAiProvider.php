<?php
/**
 * AI provider for tests: answers with a fixed text, without network.
 *
 * @package EasyRankly
 */

namespace EasyRankly\Tests\Agent;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Providers\AbstractProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * A provider registered once in the AI Client registry. Tests switch it on with configure(),
 * choose the answer and read the prompts it received.
 */
final class FakeAiProvider extends AbstractProvider {

	/**
	 * Whether the provider reports as configured (has credentials).
	 *
	 * @var bool
	 */
	public static bool $configured = false;

	/**
	 * Text the model answers.
	 *
	 * @var string
	 */
	public static string $answer = '{}';

	/**
	 * Messages of every prompt received, oldest first.
	 *
	 * @var list<list<\WordPress\AiClient\Messages\DTO\Message>>
	 */
	public static array $prompts = array();

	/**
	 * System instructions received, oldest first.
	 *
	 * @var list<string>
	 */
	public static array $instructions = array();

	/**
	 * Registers the provider (once per process) and resets its state.
	 *
	 * @param bool   $configured Whether it has credentials.
	 * @param string $answer     Text the model answers.
	 */
	public static function install( bool $configured = true, string $answer = '{}' ): void {
		$registry = AiClient::defaultRegistry();
		if ( ! $registry->hasProvider( 'easyrankly-fake' ) ) {
			$registry->registerProvider( self::class );
		}

		self::$configured   = $configured;
		self::$answer       = $answer;
		self::$prompts      = array();
		self::$instructions = array();
	}

	/**
	 * Text of the last prompt, all parts joined.
	 *
	 * @return string
	 */
	public static function last_prompt(): string {
		$text     = '';
		$messages = array() === self::$prompts ? array() : self::$prompts[ count( self::$prompts ) - 1 ];
		foreach ( $messages as $message ) {
			foreach ( $message->getParts() as $part ) {
				$text .= (string) $part->getText();
			}
		}

		return $text;
	}

	/**
	 * Provider metadata.
	 *
	 * @return ProviderMetadata
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata( 'easyrankly-fake', 'EasyRankly fake', ProviderTypeEnum::cloud() );
	}

	/**
	 * Availability that follows $configured.
	 *
	 * @return ProviderAvailabilityInterface
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new class() implements ProviderAvailabilityInterface {
			/**
			 * Whether the provider is configured.
			 *
			 * @return bool
			 */
			public function isConfigured(): bool {
				return FakeAiProvider::$configured;
			}
		};
	}

	/**
	 * One text model that accepts every option the plugin uses, and images.
	 *
	 * @return ModelMetadataDirectoryInterface
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new class() implements ModelMetadataDirectoryInterface {
			/**
			 * Lists the model.
			 *
			 * @return list<ModelMetadata>
			 */
			public function listModelMetadata(): array {
				return array( FakeAiProvider::model_metadata() );
			}

			/**
			 * Whether the model exists.
			 *
			 * @param string $model_id Model ID.
			 * @return bool
			 */
			public function hasModelMetadata( string $model_id ): bool {
				return 'fake-model' === $model_id;
			}

			/**
			 * Metadata of the model.
			 *
			 * @param string $model_id Model ID.
			 * @return ModelMetadata
			 */
			public function getModelMetadata( string $model_id ): ModelMetadata {
				return FakeAiProvider::model_metadata();
			}
		};
	}

	/**
	 * Metadata of the fake model.
	 *
	 * @return ModelMetadata
	 */
	public static function model_metadata(): ModelMetadata {
		$options = array();
		foreach ( array( 'systemInstruction', 'outputMimeType', 'outputSchema', 'outputModalities', 'inputModalities', 'temperature', 'maxTokens' ) as $option ) {
			$options[] = new SupportedOption( OptionEnum::$option(), null );
		}

		return new ModelMetadata( 'fake-model', 'Fake model', array( CapabilityEnum::textGeneration() ), $options );
	}

	/**
	 * The fake model: records the prompt and answers $answer.
	 *
	 * @param ModelMetadata    $model_metadata    Model metadata.
	 * @param ProviderMetadata $provider_metadata Provider metadata.
	 * @return ModelInterface
	 */
	protected static function createModel( ModelMetadata $model_metadata, ProviderMetadata $provider_metadata ): ModelInterface {
		return new class( $model_metadata, $provider_metadata ) implements ModelInterface, TextGenerationModelInterface {

			/**
			 * Configuration set by the builder.
			 *
			 * @var ModelConfig
			 */
			private ModelConfig $config;

			/**
			 * Stores the metadata.
			 *
			 * @param ModelMetadata    $model    Model metadata.
			 * @param ProviderMetadata $provider Provider metadata.
			 */
			public function __construct( private ModelMetadata $model, private ProviderMetadata $provider ) {
				$this->config = new ModelConfig();
			}

			/**
			 * Model metadata.
			 *
			 * @return ModelMetadata
			 */
			public function metadata(): ModelMetadata {
				return $this->model;
			}

			/**
			 * Provider metadata.
			 *
			 * @return ProviderMetadata
			 */
			public function providerMetadata(): ProviderMetadata {
				return $this->provider;
			}

			/**
			 * Sets the configuration.
			 *
			 * @param ModelConfig $config Configuration.
			 */
			public function setConfig( ModelConfig $config ): void {
				$this->config = $config;
			}

			/**
			 * Configuration.
			 *
			 * @return ModelConfig
			 */
			public function getConfig(): ModelConfig {
				return $this->config;
			}

			/**
			 * Records the prompt and answers the configured text.
			 *
			 * @param array $prompt Messages (list of \WordPress\AiClient\Messages\DTO\Message).
			 * @return GenerativeAiResult
			 */
			public function generateTextResult( array $prompt ): GenerativeAiResult {
				FakeAiProvider::$prompts[]      = $prompt;
				FakeAiProvider::$instructions[] = (string) $this->config->getSystemInstruction();

				return new GenerativeAiResult(
					'fake-result',
					array( new Candidate( new ModelMessage( array( new MessagePart( FakeAiProvider::$answer ) ) ), FinishReasonEnum::stop() ) ),
					new TokenUsage( 1, 1, 2 ),
					$this->provider,
					$this->model
				);
			}
		};
	}
}
