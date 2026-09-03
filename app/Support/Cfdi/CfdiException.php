<?php

namespace App\Support\Cfdi;

use RuntimeException;

/** Algo impidió timbrar o cancelar: falta un dato, o el PAC rechazó. */
class CfdiException extends RuntimeException {}
