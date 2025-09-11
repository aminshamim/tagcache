<?php declare(strict_types=1);

namespace TagCache\Tests\Unit\Transport;

use PHPUnit\Framework\TestCase;
use TagCache\Config;
use TagCache\Transport\HttpTransport;

/**
 * Tests for serialization functionality in HttpTransport
 */
class SerializationTest extends TestCase
{
    private function createTransport(array $config = []): HttpTransport
    {
        $defaultConfig = [
            'http' => [
                'base_url' => 'http://localhost:8080',
                'timeout_ms' => 5000,
                'serializer' => 'native', // Use native serializer for testing
                'auto_serialize' => true,
            ],
            'auth' => [
                'username' => 'admin',
                'password' => 'password',
            ],
        ];
        
        $mergedConfig = array_merge_recursive($defaultConfig, $config);
        return new HttpTransport(new Config($mergedConfig));
    }

    public function testSerializePrimitiveString(): void
    {
        $transport = $this->createTransport();
        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('serializeValue');
        $method->setAccessible(true);

        $result = $method->invoke($transport, 'hello world');
        
        $this->assertEquals('hello world', $result['value']);
        $this->assertEquals('string', $result['type']);
        $this->assertFalse($result['serialized']);
    }

    public function testSerializePrimitiveInteger(): void
    {
        $transport = $this->createTransport();
        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('serializeValue');
        $method->setAccessible(true);

        $result = $method->invoke($transport, 42);
        
        $this->assertEquals('42', $result['value']);
        $this->assertEquals('integer', $result['type']);
        $this->assertFalse($result['serialized']);
    }

    public function testSerializePrimitiveFloat(): void
    {
        $transport = $this->createTransport();
        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('serializeValue');
        $method->setAccessible(true);

        $result = $method->invoke($transport, 3.14);
        
        $this->assertEquals('3.14', $result['value']);
        $this->assertEquals('double', $result['type']);
        $this->assertFalse($result['serialized']);
    }

    public function testSerializePrimitiveBoolean(): void
    {
        $transport = $this->createTransport();
        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('serializeValue');
        $method->setAccessible(true);

        $result = $method->invoke($transport, true);
        
        $this->assertEquals('1', $result['value']);
        $this->assertEquals('boolean', $result['type']);
        $this->assertFalse($result['serialized']);

        $result = $method->invoke($transport, false);
        
        $this->assertEquals('0', $result['value']);
        $this->assertEquals('boolean', $result['type']);
        $this->assertFalse($result['serialized']);
    }

    public function testSerializePrimitiveNull(): void
    {
        $transport = $this->createTransport();
        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('serializeValue');
        $method->setAccessible(true);

        $result = $method->invoke($transport, null);
        
        $this->assertEquals('', $result['value']);
        $this->assertEquals('NULL', $result['type']);
        $this->assertFalse($result['serialized']);
    }

    public function testSerializeArray(): void
    {
        $transport = $this->createTransport();
        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('serializeValue');
        $method->setAccessible(true);

        $array = ['foo', 'bar', 'baz'];
        $result = $method->invoke($transport, $array);
        
        $this->assertEquals('array', $result['type']);
        $this->assertTrue($result['serialized']);
        
        // Value should be base64 encoded serialized data
        $decoded = base64_decode($result['value']);
        $unserialized = unserialize($decoded);
        $this->assertEquals($array, $unserialized);
    }

    public function testSerializeObject(): void
    {
        $transport = $this->createTransport();
        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('serializeValue');
        $method->setAccessible(true);

        $object = new \stdClass();
        $object->name = 'test';
        $object->value = 123;
        
        $result = $method->invoke($transport, $object);
        
        $this->assertEquals('object', $result['type']);
        $this->assertTrue($result['serialized']);
        
        // Value should be base64 encoded serialized data
        $decoded = base64_decode($result['value']);
        $unserialized = unserialize($decoded);
        $this->assertEquals($object, $unserialized);
    }

    public function testDeserializePrimitives(): void
    {
        $transport = $this->createTransport();
        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('deserializeValue');
        $method->setAccessible(true);

        // Test string
        $result = $method->invoke($transport, [
            'value' => 'hello',
            'type' => 'string',
            'serialized' => false
        ]);
        $this->assertEquals('hello', $result);

        // Test integer
        $result = $method->invoke($transport, [
            'value' => '42',
            'type' => 'integer',
            'serialized' => false
        ]);
        $this->assertEquals(42, $result);

        // Test boolean
        $result = $method->invoke($transport, [
            'value' => '1',
            'type' => 'boolean',
            'serialized' => false
        ]);
        $this->assertTrue($result);
    }

    public function testDeserializeComplexTypes(): void
    {
        $transport = $this->createTransport();
        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('deserializeValue');
        $method->setAccessible(true);

        $originalArray = ['foo', 'bar', 'baz'];
        $serialized = serialize($originalArray);
        $encoded = base64_encode($serialized);

        $result = $method->invoke($transport, [
            'value' => $encoded,
            'type' => 'array',
            'serialized' => true
        ]);
        
        $this->assertEquals($originalArray, $result);
    }

    public function testSerializationRoundTrip(): void
    {
        $transport = $this->createTransport();
        $reflection = new \ReflectionClass($transport);
        $serializeMethod = $reflection->getMethod('serializeValue');
        $deserializeMethod = $reflection->getMethod('deserializeValue');
        $serializeMethod->setAccessible(true);
        $deserializeMethod->setAccessible(true);

        $testData = [
            'string' => 'hello world',
            'integer' => 42,
            'float' => 3.14159,
            'boolean_true' => true,
            'boolean_false' => false,
            'null' => null,
            'array' => ['foo', 'bar', ['nested' => 'array']],
            'object' => (object)['name' => 'test', 'value' => 123],
        ];

        foreach ($testData as $key => $originalValue) {
            $serialized = $serializeMethod->invoke($transport, $originalValue);
            $deserialized = $deserializeMethod->invoke($transport, $serialized);
            
            $this->assertEquals($originalValue, $deserialized, "Round trip failed for: $key");
        }
    }

    public function testAutoSerializeDisabled(): void
    {
        $config = [
            'http' => [
                'base_url' => 'http://localhost:8080',
                'timeout_ms' => 5000,
                'serializer' => 'native',
                'auto_serialize' => false,
            ],
            'auth' => [
                'username' => 'admin',
                'password' => 'password',
            ],
        ];
        $transport = new HttpTransport(new Config($config));
        
        $reflection = new \ReflectionClass($transport);
        $method = $reflection->getMethod('serializeValue');
        $method->setAccessible(true);

        // String should work
        $result = $method->invoke($transport, 'hello');
        $this->assertEquals('hello', $result['value']);

        // Non-string should throw exception
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Auto-serialization is disabled. Only string values are allowed.');
        $method->invoke($transport, ['array']);
    }

    public function testSerializerValidation(): void
    {
        // Test with invalid serializer falls back to native
        $config = [
            'http' => [
                'base_url' => 'http://localhost:8080',
                'timeout_ms' => 5000,
                'serializer' => 'invalid',
                'auto_serialize' => true,
            ],
            'auth' => [
                'username' => 'admin',
                'password' => 'password',
            ],
        ];
        $transport = new HttpTransport(new Config($config));
        
        $reflection = new \ReflectionClass($transport);
        $property = $reflection->getProperty('serializer');
        $property->setAccessible(true);
        
        $this->assertEquals('native', $property->getValue($transport));
    }

    public function testIgbinarySerializerFallback(): void
    {
        // Test igbinary fallback to msgpack if not available
        $config = [
            'http' => [
                'base_url' => 'http://localhost:8080',
                'timeout_ms' => 5000,
                'serializer' => 'igbinary',
                'auto_serialize' => true,
            ],
            'auth' => [
                'username' => 'admin',
                'password' => 'password',
            ],
        ];
        $transport = new HttpTransport(new Config($config));
        
        $reflection = new \ReflectionClass($transport);
        $property = $reflection->getProperty('serializer');
        $property->setAccessible(true);
        
        // Should be igbinary if extension exists, or fall back
        $serializer = $property->getValue($transport);
        $this->assertContains($serializer, ['igbinary', 'msgpack', 'native']);
    }
}
