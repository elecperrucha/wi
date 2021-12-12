<?php

namespace phpunit\unit\includes\Settings;

use BagOStuff;
use ExtensionRegistry;
use InvalidArgumentException;
use MediaWiki\Settings\Cache\CacheableSource;
use MediaWiki\Settings\Config\ArrayConfigBuilder;
use MediaWiki\Settings\Config\MergeStrategy;
use MediaWiki\Settings\Config\PhpIniSink;
use MediaWiki\Settings\SettingsBuilder;
use MediaWiki\Settings\SettingsBuilderException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \MediaWiki\Settings\SettingsBuilder
 */
class SettingsBuilderTest extends TestCase {

	/**
	 * @param array $params
	 * @return SettingsBuilder
	 */
	private function newSettingsBuilder( $params = [] ): SettingsBuilder {
		return new SettingsBuilder(
			__DIR__,
			$params['extensionRegistry'] ?? $this->createMock( ExtensionRegistry::class ),
			$params['configBuilder'] ?? new ArrayConfigBuilder(),
			$params['phpIniSink'] ?? $this->createMock( PhpIniSink::class ),
			$params['cache'] ?? null
		);
	}

	public function testLoadingFromFile() {
		$configBuilder = new ArrayConfigBuilder();

		$phpIniSinkMock = $this->createMock( PhpIniSink::class );
		$phpIniSinkMock->expects( $this->once() )->method( 'set' )->with( 'foo', 'bar' );

		$setting = $this->newSettingsBuilder( [
			'configBuilder' => $configBuilder,
			'phpIniSink' => $phpIniSinkMock
		] );
		$setting->loadFile( 'fixtures/settings.json' )->apply();

		$config = $configBuilder->build();
		$this->assertSame( 'TEST', $config->get( 'Something' ) );
	}

	public function testLoadingAndApplyingFromFileAfterFinalize() {
		$configBuilder = new ArrayConfigBuilder();

		$phpIniSinkMock = $this->createMock( PhpIniSink::class );
		$phpIniSinkMock->expects( $this->once() )->method( 'set' )->with( 'foo', 'bar' );

		$setting = $this->newSettingsBuilder( [
			'configBuilder' => $configBuilder,
			'phpIniSink' => $phpIniSinkMock
		] );
		$setting->loadFile( 'fixtures/settings.json' )->apply();

		$config = $configBuilder->build();
		$this->assertSame( 'TEST', $config->get( 'Something' ) );

		// Finalize and lock loading & applying anymore settings
		$setting->finalize();

		$this->expectException( SettingsBuilderException::class );
		$setting->loadFile( 'fixtures/settings.json' )->apply();
	}

	public function testLoadingExtensions() {
		$extensionRegistryMock = $this->createMock( ExtensionRegistry::class );
		$extensionRegistryMock
			->expects( $this->exactly( 3 ) )
			->method( 'queue' )->withConsecutive(
				[ '/test/extensions/Foo/extension.json' ],
				[ '/test/extensions/Bar/extension.json' ],
				[ '/test/skins/Quux/skin.json' ]
			);

		$setting = $this->newSettingsBuilder( [
			'extensionRegistry' => $extensionRegistryMock,
		] );
		$setting->loadFile( 'fixtures/default-schema.json' );
		$setting->loadFile( 'fixtures/settings.json' );
		$setting->apply();
	}

	public function provideConfigOverrides() {
		yield 'sets a value from a single settings file' => [
			'settingsBatches' => [
				[ 'config' => [ 'MySetting' => 'MyValue', ], ],
			],
			'expectedGlobals' => [
				'MySetting' => 'MyValue',
			],
		];
		yield 'merges different values from multiple settings files' => [
			'settingsBatches' => [
				[ 'config' => [ 'MySetting' => 'MyValue', ], ],
				[ 'config' => [ 'MyOtherSetting' => 'MyOtherValue', ], ],
			],
			'expectedGlobals' => [
				'MySetting' => 'MyValue',
				'MyOtherSetting' => 'MyOtherValue',
			],
		];
		yield 'overrides value in config' => [
			'settingsBatches' => [
				[ 'config' => [ 'MySetting' => 'MyValue', ], ],
				[ 'config' => [ 'MySetting' => 'MyOtherValue', ], ],
			],
			'expectedGlobals' => [
				'MySetting' => 'MyOtherValue',
			],
		];
		yield 'sets a default from schema' => [
			'settingsBatches' => [
				[ 'config-schema' => [ 'MySetting' => [ 'default' => 'MyDefault', ], ], ],
			],
			'expectedGlobals' => [
				'MySetting' => 'MyDefault',
			],
		];
		yield 'value in config overrides default from schema' => [
			'settingsBatches' => [
				[
					'config-schema' => [ 'MySetting' => [ 'default' => 'MyDefault', ], ],
					'config' => [ 'MySetting' => 'MyValue', ],
				],
			],
			'expectedGlobals' => [
				'MySetting' => 'MyValue',
			],
		];
		yield 'default null is applied' => [
			'settingsBatches' => [
				[ 'config-schema' => [ 'MySetting' => [ 'default' => null, ], ], ],
			],
			'expectedGlobals' => [
				'MySetting' => null,
			],
		];
		yield 'null value can override default' => [
			'settingsBatches' => [
				[
					'config-schema' => [ 'MySetting' => [ 'default' => 'default', ], ],
					'config' => [ 'MySetting' => null, ],
				],
			],
			'expectedGlobals' => [
				'MySetting' => null,
			],
		];
		yield 'merge strategy is applied when setting config' => [
			'settingsBatches' => [
				[
					'config-schema' => [ 'MySetting' => [
						'mergeStrategy' => MergeStrategy::ARRAY_MERGE_RECURSIVE
					], ],
					'config' => [ 'MySetting' => [ 'a' => [ 'b' => 'c' ], ], ],
				],
				[
					'config' => [ 'MySetting' => [ 'a' => [ 'b' => 'd' ], ], ],
				]
			],
			'expectedGlobals' => [
				'MySetting' => [ 'a' => [ 'b' => [ 'c', 'd' ], ], ],
			],
		];
		yield 'merge strategy is applied backwards setting schema default' => [
			'settingsBatches' => [
				[
					'config' => [ 'MySetting' => [ 'a' => [ 'b' => 'd' ], ], ],
					'config-schema' => [ 'MySetting' => [
						'mergeStrategy' => MergeStrategy::ARRAY_MERGE_RECURSIVE,
						'default' => [ 'a' => [ 'b' => 'c' ], ],
					], ],
				]
			],
			'expectedGlobals' => [
				'MySetting' => [ 'a' => [ 'b' => [ 'c', 'd' ], ], ],
			],
		];
		yield 'merge strategy is applied backwards setting schema default in different batch' => [
			'settingsBatches' => [
				[
					'config' => [ 'MySetting' => [ 'a' => [ 'b' => 'd' ], ], ],
				], [
					'config-schema' => [ 'MySetting' => [
						'mergeStrategy' => MergeStrategy::ARRAY_MERGE_RECURSIVE,
						'default' => [ 'a' => [ 'b' => 'c' ], ],
					], ],
				]
			],
			'expectedGlobals' => [
				'MySetting' => [ 'a' => [ 'b' => [ 'c', 'd' ], ], ],
			],
		];
	}

	/**
	 * @dataProvider provideConfigOverrides
	 */
	public function testConfigOverrides( array $settingsBatches, array $expectedGlobals ) {
		$configBuilder = new ArrayConfigBuilder();
		$setting = $this->newSettingsBuilder( [ 'configBuilder' => $configBuilder ] );
		foreach ( $settingsBatches as $batch ) {
			$setting->loadArray( $batch );
		}
		$setting->apply();
		foreach ( $expectedGlobals as $key => $value ) {
			$this->assertSame( $value, $configBuilder->build()->get( $key ) );
		}
	}

	public function testApplyPurgesState() {
		$configBuilder = new ArrayConfigBuilder();
		$setting = $this->newSettingsBuilder( [ 'configBuilder' => $configBuilder ] );
		$setting->loadArray( [ 'config' => [ 'MySetting' => 'MyValue', ], ] )
			->apply();
		$this->assertSame( 'MyValue', $configBuilder->build()->get( 'MySetting' ) );
		$configBuilder->set( 'MySetting', 'MyOtherValue' );
		// Calling apply a second time should not redefine the global
		// since the state should be cleared
		$setting->apply();
		$this->assertSame( 'MyOtherValue', $configBuilder->build()->get( 'MySetting' ) );
	}

	public function testApplyDefaultDoesNotOverwriteExisting() {
		$configBuilder = new ArrayConfigBuilder();
		$configBuilder->set( 'MySetting', 'existing' );
		$this->newSettingsBuilder( [ 'configBuilder' => $configBuilder ] )
			->loadArray( [ 'config-schema' => [ 'MySetting' => [ 'default' => 'default' ], ], ] )
			->apply();
		$this->assertSame( 'existing', $configBuilder->build()->get( 'MySetting' ) );
	}

	public function testConfigSchemaOverrideNotAllowed() {
		$this->expectException( SettingsBuilderException::class );
		$this->newSettingsBuilder()
			->loadArray( [ 'config-schema' => [ 'MySetting' => [ 'default' => 'default' ], ], ] )
			->loadArray( [ 'config-schema' => [ 'MySetting' => [ 'default' => 'override' ], ], ] )
			->apply();
	}

	public function provideValidate() {
		yield 'all good' => [
			'settings' => [
				'config-schema' => [ 'foo' => [ 'type' => 'string', ], ],
				'config' => [ 'foo' => 'bar', ],
			],
			'valid' => true,
		];
		yield 'missing key' => [
			'settings' => [
				'config-schema' => [ 'foo' => [ 'type' => 'string', ], ],
				'config' => [ 'bar' => 'bar' ],
			],
			'valid' => false,
		];
		yield 'invalid config' => [
			'settings' => [
				'config-schema' => [ 'foo' => [ 'type' => 'string', ], ],
				'config' => [ 'foo' => 1 ],
			],
			'valid' => false,
		];
		yield 'no schema was added' => [
			'settings' => [
				'config-schema' => [],
				'config' => [ 'foo' => 'bar', ],
			],
			'valid' => true,
		];
		yield 'key is in config but has no schema' => [
			'settings' => [
				'config-schema' => [ 'foo' => [ 'type' => 'array', 'mergeStrategy' => MergeStrategy::ARRAY_MERGE ], ],
				'config' => [ 'foo' => [], 'baz' => false, ],
			],
			'valid' => true,
		];
	}

	/**
	 * @dataProvider provideValidate
	 */
	public function testValidate( array $settings, bool $valid ) {
		$status = $this->newSettingsBuilder()
			->loadArray( $settings )
			->apply()
			->validate();
		$this->assertSame( $valid, $status->isOK() );
	}

	public function testValidateInvalidSchema() {
		$this->expectException( InvalidArgumentException::class );
		$this->newSettingsBuilder()
			->loadArray( [
				'config-schema' => [ 'foo' => [ 'type' => 1 ] ],
				'config' => [ 'foo' => 'bar' ],
			] )
			->apply()
			->validate();
	}

	public function testLoadsCacheableSource() {
		$mockSource = $this->createMock( CacheableSource::class );
		$mockCache = $this->createMock( BagOStuff::class );
		$configBuilder = new ArrayConfigBuilder();
		$builder = $this
			->newSettingsBuilder( [
				'configBuilder' => $configBuilder,
				'cache' => $mockCache
			] )
			->load( $mockSource );

		$hashKey = 'abc123';
		$key = 'global:MediaWiki\Tests\Unit\Settings\Cache\CachedSourceTest:' . $hashKey;

		// Mock a cache miss
		$mockSource
			->expects( $this->once() )
			->method( 'getHashKey' )
			->willReturn( $hashKey );

		$mockCache
			->expects( $this->once() )
			->method( 'makeGlobalKey' )
			->with( 'MediaWiki\Settings\Cache\CachedSource', $hashKey )
			->willReturn( $key );

		$mockCache
			->expects( $this->once() )
			->method( 'get' )
			->with( $key )
			->willReturn( false );

		$mockSource
			->expects( $this->once() )
			->method( 'load' )
			->willReturn( [ 'config' => [ 'MySetting' => 'BlaBla' ] ] );

		$builder->apply();

		$this->assertSame( 'BlaBla', $configBuilder->build()->get( 'MySetting' ) );
	}
}
