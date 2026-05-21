<?php

declare(strict_types=1);

namespace App\Domain\Shared\ValueObjects;

/**
 * A permission identifier of the form `{module}.{action}`.
 *
 * Examples: `cookies.create`, `invoices.post`, `reports.export`.
 *
 * Permissions are case-insensitive on input; normalised to lowercase.
 * Compound permissions are NOT supported here — callers compose by listing
 * multiple permission objects.
 */
final readonly class Permission
{
    /**
     * __construct.
     */
    private function __construct(
        public string $module,
        public string $action,
        public string $name
    ) {
    }

    /**
     * fromString.
     *
     * @throws \InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $value) !== 1) {
            throw new \InvalidArgumentException(
                sprintf('Permission must look like "module.action"; got "%s"', $value)
            );
        }

        [$module, $action] = explode('.', $value, 2);

        return new self($module, $action, $value);
    }

    /**
     * equals.
     */
    public function equals(self $other): bool
    {
        return $this->name === $other->name;
    }

    /**
     * __toString.
     */
    public function __toString(): string
    {
        return $this->name;
    }
}
