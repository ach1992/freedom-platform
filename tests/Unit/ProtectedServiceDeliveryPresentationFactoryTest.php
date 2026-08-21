<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Modules\Panels\Application\Contracts\SensitiveDeliveryArtifacts;
use App\Modules\Provisioning\Application\ProtectedServiceDeliveryPresentationFactory;
use Illuminate\Config\Repository;
use LogicException;
use Tests\TestCase;

final class ProtectedServiceDeliveryPresentationFactoryTest extends TestCase
{
    public function test_numeric_environment_style_values_are_normalized_at_the_policy_boundary(): void
    {
        $factory = new ProtectedServiceDeliveryPresentationFactory(new Repository([
            'service_delivery' => [
                'presentation' => [
                    'mode' => 'text',
                    'inline_link_threshold' => '4',
                    'qr_source_index' => '0',
                    'max_document_bytes' => '1048576',
                ],
            ],
        ]));

        $presentation = $factory->make(new SensitiveDeliveryArtifacts([
            'https://subscription.example.test/current',
        ]));

        self::assertNotNull($presentation);
        self::assertTrue($presentation->isText());
        self::assertStringContainsString('https://subscription.example.test/current', $presentation->text());
    }

    public function test_invalid_policy_is_not_silently_converted_to_an_absent_delivery_artifact(): void
    {
        $factory = new ProtectedServiceDeliveryPresentationFactory(new Repository([
            'service_delivery' => [
                'presentation' => [
                    'mode' => 'text',
                    'inline_link_threshold' => '',
                    'qr_source_index' => 0,
                    'max_document_bytes' => 1_048_576,
                ],
            ],
        ]));

        $this->expectException(LogicException::class);
        $factory->make(new SensitiveDeliveryArtifacts([
            'https://subscription.example.test/current',
        ]));
    }
}
