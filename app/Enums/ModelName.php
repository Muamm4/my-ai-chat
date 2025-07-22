<?php

declare(strict_types=1);

namespace App\Enums;

use Prism\Prism\Enums\Provider;

enum ModelName: string
{
    case GEMINI_2_5_FLASH_LITE = 'gemini-2.5-flash-lite';
    case GEMINI_2_5_FLASH = 'gemini-2.5-flash';
    case GEMINI_2_5_FLASH_IMAGE_PREVIEW = 'gemini-2.5-flash-image-preview';

    /**
     * @return array{id: string, name: string, description: string, provider: string}[]
     */
    public static function getAvailableModels(): array
    {
        return array_map(
            fn (ModelName $model): array => $model->toArray(),
            self::cases()
        );
    }

    public function getName(): string
    {
        return match ($this) {
            self::GEMINI_2_5_FLASH_LITE => 'Gemini 2.5 Flash Lite',
            self::GEMINI_2_5_FLASH => 'Gemini 2.5 Flash',
            self::GEMINI_2_5_FLASH_IMAGE_PREVIEW => 'Gemini 2.5 Flash Image Preview',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::GEMINI_2_5_FLASH_LITE => 'Cheapest model, best for smarter tasks',
            self::GEMINI_2_5_FLASH => 'Cheapest model, best for simpler tasks',
            self::GEMINI_2_5_FLASH_IMAGE_PREVIEW => 'Cheapest model, best for simpler tasks',
        };
    }

    public function getProvider(): Provider
    {
        return match ($this) {
            self::GEMINI_2_5_FLASH_LITE => Provider::Gemini,
            self::GEMINI_2_5_FLASH => Provider::Gemini,
            self::GEMINI_2_5_FLASH_IMAGE_PREVIEW => Provider::Gemini,
        };
    }

    /**
     * @return array{id: string, name: string, description: string, provider: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->value,
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'provider' => $this->getProvider()->value,
        ];
    }
}
