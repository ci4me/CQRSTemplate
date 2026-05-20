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
     *
     * @param string $module
     * @param string $action
     * @param string $name
     * @todo Auto-generated docblock — review and replace this description.
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
     * @param string $value
     * @return self
     * @throws \InvalidArgumentException
     * @todo Auto-generated docblock — review and replace this description.
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
     *
     * @param self $other
     * @return bool
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function equals(self $other): bool
    {
        return $this->name === $other->name;
    }

    /**
     * __toString.
     *
     * @return string
     * @todo Auto-generated docblock — review and replace this description.
     */
    public function __toString(): string
    {
        return $this->name;
    }
}
