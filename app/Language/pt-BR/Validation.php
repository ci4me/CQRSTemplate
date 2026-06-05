<?php

declare(strict_types=1);

/**
 * pt-BR validation-message overrides (E14, round-4 R4).
 *
 * Mirrors app/Language/en/Validation.php: both files intentionally return
 * an empty array, delegating every message to CodeIgniter's framework
 * defaults for the active locale. The file EXISTS so the locale set is
 * complete — before round 4, pt-BR had no Validation.php at all, which
 * broke the "every en key resolves in pt-BR" parity contract and made the
 * missing-translation case impossible to distinguish from a missing file.
 *
 * Add project-specific overrides as `'rule' => 'mensagem'` pairs here and
 * in the en twin TOGETHER, never one-sided.
 */

return [];
