<?php

declare(strict_types=1);

/*
 * @copyright   2018 Mautic Inc. All rights reserved
 * @author      Mautic, Inc.
 *
 * @link        https://www.mautic.com
 *
 * @license     GNU/GPLv3 http://www.gnu.org/licenses/gpl-3.0.html
 */

namespace MauticPlugin\MauticVtigerCrmBundle\Vtiger\Model\Validator;

use MauticPlugin\MauticVtigerCrmBundle\Exceptions\InvalidObjectException;
use MauticPlugin\MauticVtigerCrmBundle\Exceptions\Validation\InvalidObject;
use MauticPlugin\MauticVtigerCrmBundle\Vtiger\Model\BaseModel;
use MauticPlugin\MauticVtigerCrmBundle\Vtiger\Model\ModuleFieldInfo;
use MauticPlugin\MauticVtigerCrmBundle\Vtiger\Repository\UserRepository;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\NotNull;
use Symfony\Component\Validator\Validation;

class GeneralValidator
{
    /** @var UserRepository */
    private $userRepository;

    /** @var \Symfony\Component\Validator\ValidatorInterface */
    private $validator;

    /** @var array */
    private $existingUsersIds = [];

    /**
     * @param UserRepository $userRepository
     */
    public function __construct(UserRepository $userRepository)
    {
        $this->userRepository = $userRepository;
        $this->validator      = Validation::createValidator();    // Use symfony validator TODO inject
    }

    /**
     * @param BaseModel $object
     * @param array     $description
     *
     * @throws InvalidObject
     * @throws InvalidObjectException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    public function validateObject(BaseModel $object, array $description): void
    {
        foreach ($object->dehydrate() as $fieldName => $fieldValue) {
            $fieldDescription = $description[$fieldName];
            $this->validateField($fieldDescription, $fieldValue);
        }
    }

    /**
     * @param ModuleFieldInfo $fieldInfo
     * @param                 $fieldValue
     *
     * @throws InvalidObject
     * @throws InvalidObjectException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function validateField(ModuleFieldInfo $fieldInfo, $fieldValue): void
    {
        $validators = [];
        if (!$fieldInfo->isNullable() && $fieldInfo->isMandatory() && null === $fieldValue) {
            $validators[] = new NotNull();
        }

        //  Validate by data type
        $validators = array_merge($validators, $this->getValidatorsForType($fieldInfo->getTypeObject(), $fieldValue));

        if (!count($validators)) {
            return;
        }

        //  Validate for required fields
        $violations = $this->validator->validate($fieldValue, $validators);
        if (!count($violations)) {
            return;
        }

        throw new InvalidObject($violations, $fieldInfo, $fieldValue);
    }

    /**
     * @param $typeObject
     * @param $fieldValue
     *
     * @return array
     *
     * @throws InvalidObjectException
     * @throws \Psr\Cache\InvalidArgumentException
     */
    private function getValidatorsForType($typeObject, $fieldValue): array
    {
        $validators = [];

        switch ($typeObject->name) {
            case 'autogenerated':
            case 'string':
            case 'phone':
            case 'text':
                break;
            case 'email':
                $validators[] = new Email();
                break;
            case 'owner':
                if (!count($this->existingUsersIds)) {
                    $users                  = $this->userRepository->findBy();
                    $this->existingUsersIds = array_map(function (BaseModel $o) { return $o->getId(); }, $users);
                }

                $validators[] = new Choice(['choices' => $this->existingUsersIds]);
                break;
            case 'reference':
                break;
            case 'boolean':
                break;
            default:
                throw new InvalidObjectException('Unknown field type '.print_r((array) $typeObject, true));
        }

        return $validators;
    }
}
