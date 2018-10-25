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

namespace MauticPlugin\MauticVtigerCrmBundle\Sync;

use MauticPlugin\IntegrationsBundle\Entity\ObjectMapping;
use MauticPlugin\IntegrationsBundle\Sync\DAO\Mapping\UpdatedObjectMappingDAO;
use MauticPlugin\IntegrationsBundle\Sync\DAO\Sync\Order\ObjectChangeDAO;
use MauticPlugin\IntegrationsBundle\Sync\DAO\Sync\Report\FieldDAO;
use MauticPlugin\IntegrationsBundle\Sync\DAO\Sync\Report\ObjectDAO;
use MauticPlugin\IntegrationsBundle\Sync\DAO\Sync\Report\ReportDAO;
use MauticPlugin\IntegrationsBundle\Sync\DAO\Value\NormalizedValueDAO;
use MauticPlugin\IntegrationsBundle\Sync\Exception\ObjectDeletedException;
use MauticPlugin\IntegrationsBundle\Sync\Helper\MappingHelper;
use MauticPlugin\IntegrationsBundle\Sync\Logger\DebugLogger;
use MauticPlugin\IntegrationsBundle\Sync\ValueNormalizer\ValueNormalizerInterface;
use MauticPlugin\MauticVtigerCrmBundle\Exceptions\VtigerPluginException;
use MauticPlugin\MauticVtigerCrmBundle\Integration\Provider\VtigerSettingProvider;
use MauticPlugin\MauticVtigerCrmBundle\Integration\VtigerCrmIntegration;
use MauticPlugin\MauticVtigerCrmBundle\Mapping\ObjectFieldMapper;
use MauticPlugin\MauticVtigerCrmBundle\Sync\ValueNormalizer\Transformers\TransformerInterface;
use MauticPlugin\MauticVtigerCrmBundle\Vtiger\Model\Contact;
use MauticPlugin\MauticVtigerCrmBundle\Vtiger\Model\Validator\ContactValidator;
use MauticPlugin\MauticVtigerCrmBundle\Vtiger\Repository\ContactRepository;
use MauticPlugin\MauticVtigerCrmBundle\Vtiger\Repository\Mapping\ModelFactory;

class ContactDataExchange extends GeneralDataExchange
{
    /** @var string */
    public const OBJECT_NAME = 'Contacts';

    /** @var int */
    private const VTIGER_API_QUERY_LIMIT = 100;

    /** @var ContactRepository */
    private $contactRepository;

    /** @var ContactValidator */
    private $contactValidator;

    /** @var MappingHelper */
    private $mappingHelper;

    /** @var ObjectFieldMapper */
    private $objectFieldMapper;

    /** @var ModelFactory */
    private $modelFactory;

    /**
     * @param VtigerSettingProvider    $vtigerSettingProvider
     * @param ValueNormalizerInterface $valueNormalizer
     * @param ContactRepository        $contactRepository
     * @param ContactValidator         $contactValidator
     * @param MappingHelper            $mappingHelper
     * @param ObjectFieldMapper        $objectFieldMapper
     * @param ModelFactory             $modelFactory
     */
    public function __construct(
        VtigerSettingProvider $vtigerSettingProvider,
        ValueNormalizerInterface $valueNormalizer,
        ContactRepository $contactRepository,
        ContactValidator $contactValidator,
        MappingHelper $mappingHelper,
        ObjectFieldMapper $objectFieldMapper,
        ModelFactory $modelFactory
    )
    {
        parent::__construct($vtigerSettingProvider, $valueNormalizer);
        $this->contactRepository = $contactRepository;
        $this->contactValidator  = $contactValidator;
        $this->mappingHelper     = $mappingHelper;
        $this->objectFieldMapper = $objectFieldMapper;
        $this->modelFactory      = $modelFactory;
    }

    /**
     * @param \MauticPlugin\IntegrationsBundle\Sync\DAO\Sync\Request\ObjectDAO $requestedObject
     * @param ReportDAO                                                        $syncReport
     *
     * @return ReportDAO
     * @throws \MauticPlugin\IntegrationsBundle\Exception\PluginNotConfiguredException
     * @throws \MauticPlugin\IntegrationsBundle\Sync\Exception\ObjectNotFoundException
     * @throws \MauticPlugin\IntegrationsBundle\Sync\Exception\ObjectNotSupportedException
     * @throws \MauticPlugin\MauticVtigerCrmBundle\Exceptions\AccessDeniedException
     * @throws \MauticPlugin\MauticVtigerCrmBundle\Exceptions\DatabaseQueryException
     * @throws \MauticPlugin\MauticVtigerCrmBundle\Exceptions\InvalidQueryArgumentException
     * @throws \MauticPlugin\MauticVtigerCrmBundle\Exceptions\InvalidRequestException
     * @throws \MauticPlugin\MauticVtigerCrmBundle\Exceptions\SessionException
     * @throws VtigerPluginException
     */
    public function getObjectSyncReport(
        \MauticPlugin\IntegrationsBundle\Sync\DAO\Sync\Request\ObjectDAO $requestedObject,
        ReportDAO $syncReport
    ): ReportDAO
    {
        $fromDateTime = $requestedObject->getFromDateTime();
        $mappedFields = $requestedObject->getFields();
        $objectFields = $this->contactRepository->describe()->getFields();

        $mappedFields = array_merge($mappedFields, [
            'isconvertedfromlead', 'leadsource', 'reference', 'source', 'contact_id', 'emailoptout', 'donotcall',
        ]);

        $deleted = [];
        $updated = $this->getReportPayload($fromDateTime, $mappedFields, self::OBJECT_NAME);

        /** @var Contact $contact */
        foreach ($updated as $key => $contact) {
            if ($contact->isConvertedFromLead()) {
                $objectDAO = new ObjectDAO(LeadDataExchange::OBJECT_NAME, $contact->getId(), $contact->getModifiedTime());
                $objectDAO->addField(
                    new FieldDAO('email', $this->valueNormalizer->normalizeForMautic(NormalizedValueDAO::EMAIL_TYPE, $contact->getEmail()))
                );
                try {
                    // beware this method also saves it :-(
                    $foundMapping = $this->mappingHelper->findMauticObject(
                        $this->objectFieldMapper->getObjectsMappingManual(),
                        'lead',
                        $objectDAO
                    );
                }
                catch (ObjectDeletedException $e) {
                    $foundMapping = false;
                }


                // This lead has to be marked as deleted
                if ($foundMapping) {
                    DebugLogger::log(VtigerCrmIntegration::NAME, 'Marking Lead #' . $contact->getId() . ' as deleted');
                    $objectChangeDAO = new ObjectChangeDAO(
                        VtigerCrmIntegration::NAME,
                        LeadDataExchange::OBJECT_NAME,
                        $contact->getId(),
                        $foundMapping->getObject(),
                        $foundMapping->getObjectId()
                    );

                    $mapping = (new ObjectMapping())
                        ->setIntegration(VtigerCrmIntegration::NAME)
                        ->setIntegrationObjectName(self::OBJECT_NAME)
                        ->setIntegrationObjectId($contact->getId())
                        ->setInternalObjectName($foundMapping->getObject())
                        ->setInternalObjectId($foundMapping->getObjectId())
                        ->setLastSyncDate($foundMapping->getChangeDateTime());

                    DebugLogger::log(VtigerCrmIntegration::NAME, 'Remapping Lead to Contact');

                    $this->mappingHelper->saveObjectMappings([
                        $mapping,
                    ]);

                    $deleted[] = $objectChangeDAO;

                    unset($updated[$key]);
                }
            }
        }

        $this->mappingHelper->markAsDeleted($deleted);

        /** @var Contact $object */
        foreach ($updated as $object) {
            $objectDAO = new ObjectDAO(self::OBJECT_NAME, $object->getId(), new \DateTimeImmutable($object->getModifiedTime()->format('r')));

            foreach ($object->dehydrate($mappedFields) as $field => $value) {
                // Normalize the value from the API to what Mautic needs
                $normalizedValue = $this->valueNormalizer->normalizeForMautic($objectFields[$field]->getType(), $value);
                $reportFieldDAO  = new FieldDAO($field, $normalizedValue);

                $objectDAO->addField($reportFieldDAO);
            }

            $objectDAO->addField(
                new FieldDAO(
                    'mautic_internal_dnc_email',
                    $this->valueNormalizer->normalizeForMautic(TransformerInterface::DNC_TYPE, $object->getEmailOptout())
                )
            );
            $objectDAO->addField(
                new FieldDAO(
                    'mautic_internal_dnc_sms',
                    $this->valueNormalizer->normalizeForMautic(TransformerInterface::DNC_TYPE, $object->getEmailOptout())
                )
            );

            $syncReport->addObject($objectDAO);
        }

        return $syncReport;
    }

    /**
     * @param array             $ids
     * @param ObjectChangeDAO[] $objects
     *
     * @return UpdatedObjectMappingDAO[]
     */
    public function update(array $ids, array $objects): array
    {
        return $this->updateInternal($ids, $objects, self::OBJECT_NAME);
    }

    /**
     * @param ObjectChangeDAO[] $objects
     *
     * @return array|ObjectMapping[]
     * @throws VtigerPluginException
     */
    public function insert(array $objects): array
    {
        if (!$this->vtigerSettingProvider->shouldBeMauticContactPushedAsContact()) {
            return [];
        }

        return $this->insertInternal($objects, self::OBJECT_NAME);
    }

    /**
     * @param array $objectData
     *
     * @return Contact
     */
    protected function getModel(array $objectData): Contact
    {
        return $this->modelFactory->createContact($objectData);
    }

    /**
     * @return ContactValidator
     */
    protected function getValidator(): ContactValidator
    {
        return $this->contactValidator;
    }

    /**
     * @return ContactRepository
     */
    protected function getRepository(): ContactRepository
    {
        return $this->contactRepository;
    }

    /**
     * @return int
     */
    protected function getVtigerApiQueryLimit(): int
    {
        return self::VTIGER_API_QUERY_LIMIT;
    }
}
