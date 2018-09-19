<?php
declare(strict_types=1);

/*
 * @copyright   2018 Mautic Inc. All rights reserved
 * @author      Mautic, Inc. Jan Kozak <galvani78@gmail.com>
 *
 * @link        http://mautic.com
 * @created     17.9.18
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticVtigerCrmBundle\Vtiger\Model\Validator;


use MauticPlugin\MauticVtigerCrmBundle\Exceptions\InvalidQueryArgumentException;
use MauticPlugin\MauticVtigerCrmBundle\Exceptions\InvalidObjectException;
use MauticPlugin\MauticVtigerCrmBundle\Exceptions\InvalidObjectValueException;
use MauticPlugin\MauticVtigerCrmBundle\Vtiger\Model\BaseModel;
use MauticPlugin\MauticVtigerCrmBundle\Vtiger\Model\Contact;

class ContactValidator implements ObjectValidatorInterface
{
    use ObjectValidatorTrait;

    /**
     * @param Contact $object
     */
    public function validate(BaseModel $object): void
    {
        if (!$object instanceof BaseRepository::$moduleClassMapping[$this->objectRepository->getModuleFromRepositoryName]) {
            throw new \InvalidArgumentException('This validator supports only Contact object');
        }

        $description = $this->objectRepository->describe();
        var_dump($description); die();
    }

}