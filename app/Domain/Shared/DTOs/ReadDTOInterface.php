<?php

declare(strict_types=1);

namespace App\Domain\Shared\DTOs;

/**
 * Contract every read-side DTO implements (E10, round-4 R3).
 *
 * Standardises the JSON boundary across all domains so API consumers see
 * ONE shape convention regardless of which aggregate they read:
 *
 *  - keys are snake_case (`formatted_price`, not `formattedPrice`);
 *  - timestamps are ISO-8601 / RFC 3339 strings;
 *  - values are scalars or null — read DTOs carry data, not behaviour.
 *
 * `jsonSerialize()` MUST return the same shape as `toArray()` so
 * `json_encode($dto)` and manual array handling never diverge.
 */
interface ReadDTOInterface extends \JsonSerializable
{
    /**
     * Snake_case wire representation of the DTO.
     *
     * @return array<string, scalar|null>
     */
    public function toArray(): array;
}
