<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application;

final readonly class ReportMetric
{
    public function __construct(
        public string $section,
        public string $code,
        public string $label,
        public int $value,
        public string $unit,
        public ?string $dimension = null,
        public ?int $priorValue = null,
    ) {}

    public function key(): string
    {
        return $this->section."\0".$this->code."\0".($this->dimension ?? '');
    }

    public function withPrior(?int $priorValue): self
    {
        return new self(
            $this->section,
            $this->code,
            $this->label,
            $this->value,
            $this->unit,
            $this->dimension,
            $priorValue,
        );
    }
}
