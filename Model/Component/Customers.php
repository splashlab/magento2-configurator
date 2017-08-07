<?php
namespace CtiDigital\Configurator\Model\Component;

use CtiDigital\Configurator\Model\LoggingInterface;
use CtiDigital\Configurator\Model\Exception\ComponentException;
use Magento\Framework\ObjectManagerInterface;
use FireGento\FastSimpleImport\Model\ImporterFactory;
use Magento\ImportExport\Model\Import;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Customer\Api\GroupManagementInterface;

class Customers extends CsvComponentAbstract
{
    const CUSTOMER_GROUP_HEADER = 'group_id';

    protected $alias = 'customers';
    protected $name = 'Customers';
    protected $description = 'Import customers and addresses';

    protected $requiredColumns = [
        'email',
        '_website',
        '_store',
    ];

    /**
     * @var \Magento\Indexer\Model\IndexerFactory
     */
    protected $indexerFactory;

    /**
     * @var ImporterFactory
     */
    protected $importerFactory;

    /**
     * @var GroupRepositoryInterface
     */
    protected $groupRepository;

    /**
     * @var GroupManagementInterface
     */
    protected $groupManagement;

    /**
     * @var SearchCriteriaBuilder
     */
    protected $searchCriteriaBuilder;

    /**
     * @var array
     */
    protected $customerGroups;

    /**
     * @var int
     */
    protected $groupDefault;

    /**
     * @var array
     */
    protected $columnHeaders = [];

    public function __construct(
        LoggingInterface $log,
        ObjectManagerInterface $objectManager,
        ImporterFactory $importerFactory,
        GroupRepositoryInterface $groupRepository,
        GroupManagementInterface $groupManagement,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        \Magento\Indexer\Model\IndexerFactory $indexerFactory
    ) {
        $this->importerFactory = $importerFactory;
        $this->groupRepository = $groupRepository;
        $this->groupManagement = $groupManagement;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->indexerFactory = $indexerFactory;
        parent::__construct($log, $objectManager);
    }

    protected function processData($data = null)
    {
        $this->getColumnHeaders($data);
        unset($data[0]);

        $customerImport = [];

        foreach ($data as $customer) {
            $row = [];
            foreach ($this->getHeaders() as $key => $columnHeader) {
                $row[$columnHeader] = $customer[$key];
                if ($columnHeader === self::CUSTOMER_GROUP_HEADER &&
                    $this->getIsValidGroup($row[$columnHeader]) === false
                ) {
                    $this->log->logError(
                        sprintf(
                            'The customer group ID "%s" is not valid. Default value set.',
                            $row[$columnHeader]
                        )
                    );
                    $row[self::CUSTOMER_GROUP_HEADER] = $this->getDefaultGroupId();
                }
            }
            $customerImport[] = $row;
        }

        try {
            /**
             * @var $importer \FireGento\FastSimpleImport\Model\Importer
             */
            $importer = $this->importerFactory->create();
            $importer->setEntityCode('customer_composite');
            $importer->setBehavior(Import::BEHAVIOR_APPEND);
            $importer->processImport($customerImport);
            $this->reindex();
        } catch (\Exception $e) {
            $this->log->logError($e->getMessage());
        }
        $this->log->logInfo($importer->getLogTrace());
        $this->log->logInfo($importer->getErrorMessages());
    }

    /**
     * Check the headers have been set correctly
     *
     * @param $data
     *
     * @return void
     */
    public function getColumnHeaders($data)
    {
        if (!isset($data[0])) {
            throw new ComponentException('No data has been found in the import file');
        }
        foreach ($data[0] as $heading) {
            $this->columnHeaders[] = $heading;
        }
        foreach ($this->requiredColumns as $column) {
            if (!in_array($column, $this->columnHeaders)) {
                throw new ComponentException(sprintf('The column "%s" is required.', $column));
            }
        }
    }

    /**
     * @return array
     */
    public function getHeaders()
    {
        return $this->columnHeaders;
    }

    /**
     * Check if the group is valid
     *
     * @param $group
     *
     * @return bool
     */
    public function getIsValidGroup($group)
    {
        if (strlen($group) === 0) {
            return false;
        }
        if ($this->customerGroups === null) {
            $groups = $this->groupRepository->getList($this->searchCriteriaBuilder->create());
            foreach ($groups->getItems() as $customerGroup) {
                $this->customerGroups[] = $customerGroup->getId();
            }
        }
        if (in_array($group, $this->customerGroups)) {
            return true;
        }
        return false;
    }

    /**
     * Get the default group
     *
     * @return int
     */
    public function getDefaultGroupId()
    {
        if ($this->groupDefault === null) {
            $this->groupDefault = $this->groupManagement->getDefaultGroup()->getId();
        }
        return $this->groupDefault;
    }

    /**
     * Reindex the customer grid
     *
     * @return void
     */
    private function reindex()
    {
        $this->log->logInfo('Reindexing the customer grid');
        $customerGrid = $this->indexerFactory->create();
        $customerGrid->load('customer_grid');
        $customerGrid->reindexAll();
    }
}
