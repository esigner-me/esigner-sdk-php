<?php

declare(strict_types=1);

namespace Esigner;

/** A webhook whose signature or timestamp did not check out. Answer such a request with 400. */
final class SignatureVerificationException extends \RuntimeException
{
}
