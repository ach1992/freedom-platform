<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application;

use InvalidArgumentException;

final readonly class ClientGuideResourceDefinition
{
    public function __construct(
        public string $code,
        public string $titleFa,
        public ?string $titleEn,
        public ?string $descriptionFa,
        public ?string $descriptionEn,
        public string $platform,
        public string $language,
        public string $audience,
        public ?string $tierCode,
        public ?string $customerTagCode,
        public string $resourceUrl,
        public ?string $tutorialFa,
        public ?string $tutorialEn,
        public ?string $normalEmoji,
        public ?string $premiumEmojiId,
        public int $sortOrder,
        public string $state = 'active',
    ) {
        $this->token($code, 'Client-guide code', 64);
        $this->text($titleFa, 'Client-guide Persian title', 191, false);
        $this->text($titleEn, 'Client-guide English title', 191, true);
        $this->text($descriptionFa, 'Client-guide Persian description', 1500, true);
        $this->text($descriptionEn, 'Client-guide English description', 1500, true);
        $this->token($platform, 'Client-guide platform', 32);
        if (! in_array($language, ['any', 'fa', 'en'], true)) {
            throw new InvalidArgumentException('Client-guide language is invalid.');
        }
        if (! in_array($audience, ['all', 'customers', 'agents'], true)) {
            throw new InvalidArgumentException('Client-guide audience is invalid.');
        }
        if ($tierCode !== null) {
            $this->token($tierCode, 'Client-guide tier', 32);
        }
        if ($customerTagCode !== null) {
            $this->token($customerTagCode, 'Client-guide customer tag', 64);
        }
        if (($tierCode !== null || $customerTagCode !== null) && $audience !== 'customers') {
            throw new InvalidArgumentException('Client-guide tier/tag visibility requires customer audience.');
        }
        $this->text($tutorialFa, 'Client-guide Persian tutorial', 2000, true);
        $this->text($tutorialEn, 'Client-guide English tutorial', 2000, true);
        $this->text($normalEmoji, 'Client-guide normal emoji', 8, true);
        if ($premiumEmojiId !== null && preg_match('/\A[0-9]{1,64}\z/', $premiumEmojiId) !== 1) {
            throw new InvalidArgumentException('Client-guide premium emoji ID is invalid.');
        }
        if ($sortOrder < 0 || $sortOrder > 1_000_000) {
            throw new InvalidArgumentException('Client-guide sort order is invalid.');
        }
        if (! in_array($state, ['active', 'disabled'], true)) {
            throw new InvalidArgumentException('Client-guide state is invalid.');
        }
        ClientGuideUrlPolicy::assertAllowed($resourceUrl);
    }

    /** @return array<string,bool|int|string|null> */
    public function payload(): array
    {
        return [
            'audience' => $this->audience,
            'code' => $this->code,
            'customer_tag_code' => $this->customerTagCode,
            'description_en' => $this->descriptionEn,
            'description_fa' => $this->descriptionFa,
            'language' => $this->language,
            'normal_emoji' => $this->normalEmoji,
            'platform' => $this->platform,
            'premium_emoji_id' => $this->premiumEmojiId,
            'resource_url' => $this->resourceUrl,
            'sort_order' => $this->sortOrder,
            'state' => $this->state,
            'tier_code' => $this->tierCode,
            'title_en' => $this->titleEn,
            'title_fa' => $this->titleFa,
            'tutorial_en' => $this->tutorialEn,
            'tutorial_fa' => $this->tutorialFa,
        ];
    }

    private function token(string $value, string $label, int $maxLength): void
    {
        if (strlen($value) > $maxLength || preg_match('/\A[a-z0-9][a-z0-9_.-]*\z/', $value) !== 1) {
            throw new InvalidArgumentException($label.' is invalid.');
        }
    }

    private function text(?string $value, string $label, int $maxLength, bool $nullable): void
    {
        if ($value === null) {
            if (! $nullable) {
                throw new InvalidArgumentException($label.' is required.');
            }

            return;
        }
        if ((! $nullable && trim($value) === '')
            || mb_strlen($value) > $maxLength
            || ! mb_check_encoding($value, 'UTF-8')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new InvalidArgumentException($label.' is invalid.');
        }
    }
}
