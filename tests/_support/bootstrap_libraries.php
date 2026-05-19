<?php

declare(strict_types=1);

/**
 * Bootstrap file for third-party libraries
 *
 * Include all custom library bootstraps here
 */

$abiSageBootstrap = HOMEPATH . 'app/Libraries/AbiSageIntacct/bootstrap.php';

if (is_file($abiSageBootstrap)) {
    require_once $abiSageBootstrap;
}
