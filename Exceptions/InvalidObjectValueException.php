<?php

declare(strict_types=1);

/*
 * @copyright   2018 Mautic Inc. All rights reserved
 * @author      Mautic, Inc.
 *
 * @link        http://mautic.org
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticVtigerCrmBundle\Exceptions;

use Exception;
use Throwable;

class InvalidObjectValueException extends Exception
{
    public function __construct(string $message, string $value, string $type)
    {
        $message = sprintf("Failed to normalize value '%s' of type '%s'. %s", $value, $type, $message)
        parent::__construct($message);
    }
}
