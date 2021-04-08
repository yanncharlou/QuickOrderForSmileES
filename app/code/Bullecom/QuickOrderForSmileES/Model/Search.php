<?php
/**
 * @author Amasty Team
 * @copyright Copyright (c) 2021 Amasty (https://www.amasty.com)
 * @package Amasty_QuickOrder
 */


declare(strict_types=1);

namespace Bullecom\QuickOrderForSmileES\Model;

use Amasty\QuickOrder\Api\Search\ProductInterface;
use Amasty\QuickOrder\Api\SearchInterface;
use Amasty\QuickOrder\Model\Elasticsearch\Adapter\DataMapper\StockStatus;
use Amasty\QuickOrder\Model\Search\ProductFactory;
use InvalidArgumentException;
use Magento\Bundle\Model\Product\Type as Bundle;
use Magento\Catalog\Api\Data\ProductSearchResultsInterfaceFactory;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Catalog\Pricing\Price\ConfiguredPrice;
use Magento\Catalog\Pricing\Price\FinalPrice;
use Magento\Framework\Api\FilterBuilder;
use Magento\Framework\Api\Search\SearchCriteriaBuilder;
use Magento\Framework\Api\Search\SearchCriteriaInterface;
use Magento\Framework\Api\Search\SearchInterface as MagentoSearch;
use Magento\Framework\App\Area;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Stdlib\StringUtils;
use Magento\Search\Helper\Data as SearchHelper;
use Magento\Store\Model\App\Emulation as AppEmulation;
use Magento\Store\Model\StoreManagerInterface;

use \Smile\ElasticsuiteCore\Search\Request\Builder as RequestBuilder;
use \Magento\Search\Model\SearchEngine;

class Search implements SearchInterface
{

    /**
     * @var FilterBuilder
     */
    private $filterBuilder;

    /**
     * @var SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var MagentoSearch
     */
    private $search;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * @var ProductSearchResultsInterfaceFactory
     */
    private $searchResultsFactory;

    /**
     * @var Visibility
     */
    private $visibility;

    /**
     * @var ConfigProvider
     */
    private $configProvider;

    /**
     * @var SearchHelper
     */
    private $searchHelper;

    /**
     * @var StringUtils
     */
    private $stringUtils;

    /**
     * @var ProductFactory
     */
    private $searchProductFactory;

    /**
     * @var PriceCurrencyInterface
     */
    private $priceCurrency;

    /**
     * @var Image
     */
    private $imageModel;

    /**
     * @var AppEmulation
     */
    private $appEmulation;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var \Magento\Framework\View\LayoutInterface
     */
    private $layout;

    /**
     * @var \Magento\Framework\View\Result\PageFactory
     */
    private $resultPageFactory;

    private $requestBuilder;
    private $searchEngine;
    private $searchTerm;

    public function __construct(
        FilterBuilder $filterBuilder,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        Visibility $visibility,
        MagentoSearch $search,
        CollectionFactory $collectionFactory,
        \Amasty\QuickOrder\Model\ConfigProvider $configProvider,
        ProductSearchResultsInterfaceFactory $searchResultsFactory,
        SearchHelper $searchHelper,
        StringUtils $stringUtils,
        ProductFactory $searchProductFactory,
        PriceCurrencyInterface $priceCurrency,
        \Amasty\QuickOrder\Model\Image $imageModel,
        AppEmulation $appEmulation,
        StoreManagerInterface $storeManager,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        RequestBuilder $requestBuilder,
        SearchEngine $searchEngine
    ) {
        $this->filterBuilder = $filterBuilder;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->search = $search;
        $this->collectionFactory = $collectionFactory;
        $this->searchResultsFactory = $searchResultsFactory;
        $this->visibility = $visibility;
        $this->configProvider = $configProvider;
        $this->searchHelper = $searchHelper;
        $this->stringUtils = $stringUtils;
        $this->searchProductFactory = $searchProductFactory;
        $this->priceCurrency = $priceCurrency;
        $this->imageModel = $imageModel;
        $this->appEmulation = $appEmulation;
        $this->storeManager = $storeManager;
        $this->resultPageFactory = $resultPageFactory;

        $this->requestBuilder = $requestBuilder;
        $this->searchEngine = $searchEngine;
    }


    /**
     * @return array
     */
    private function searchProductIds()
    {
        $productIds = [];

        $filters = [
            'visibility' => ['in' => $this->visibility->getVisibleInCatalogIds()],
            //'is_in_stock' => ??
        ];

        $searchRequest = $this->requestBuilder->create(
            $this->storeManager->getStore()->getId(),  //$storeId Search request store id.
            'quick_search_container', //$containerName Search request name.
            0, //$from Search request pagination from clause.
            10, //$size Search request pagination size.
            $this->searchTerm, //$queryText Search request fulltext query.
            [],//$sortOrders Search request sort orders.
            $filters //$filters Search request filters.
            //$queryFilters Search request filters prebuilt as QueryInterface.
            //$facets Search request facets.
        );

        $queryResponse = $this->searchEngine->search($searchRequest);

        foreach ($queryResponse->getIterator() as $item) {
            $productIds[] = $item->getId();
        }

        return $productIds;
    }

        /**
     * @param string $searchTerm
     * @return ProductInterface[]
     * @throw InvalidArgumentException
     */
    public function search(string $searchTerm)
    {
        /*
        $this->prepareSearchTerm($searchTerm);
        $this->prepareVisibility();
        $this->prepareStockFilter();
        $this->setLimit();
        */
        
        ///Filter term
        $searchTermLength = $this->stringUtils->strlen($searchTerm);

        if ($searchTermLength > $this->searchHelper->getMaxQueryLength()) {
            throw new InvalidArgumentException(
                __('Maximum Search query length is %1', $this->searchHelper->getMaxQueryLength())->__toString()
            );

        } elseif ($searchTermLength < $this->searchHelper->getMinQueryLength()) {
            throw new InvalidArgumentException(
                __('Minimum Search query length is %1', $this->searchHelper->getMinQueryLength())->__toString()
            );
        }

        $this->searchTerm = $searchTerm;

        return $this->generateSearchResult();
    }

    /**
     * @return ProductInterface[]
     */
    private function generateSearchResult(): array
    {
        $searchResult = [];

        $this->appEmulation->startEnvironmentEmulation(
            $this->storeManager->getStore()->getId(),
            Area::AREA_FRONTEND,
            true
        );

        /** @var Product $product */
        foreach ($this->getProductCollection()->getItems() as $product) {
            /** @var ProductInterface $searchProduct */
            $searchProduct = $this->searchProductFactory->create();
            $searchProduct->setId($product->getId());
            $searchProduct->setName($product->getName());
            $searchProduct->setSku($product->getSku());
            $searchProduct->setImage($this->imageModel->getUrl($product));
            $searchProduct->setPrice($this->getPriceHtml($product));
            $searchProduct->setTypeId($product->getTypeId());
            $searchResult[] = $searchProduct;
        }

        $this->appEmulation->stopEnvironmentEmulation();

        return $searchResult;
    }

    /**
     * @param Product $product
     *
     * @return string
     */
    protected function getPriceHtml(Product $product)
    {
        /** @var \Magento\Framework\Pricing\Render $priceRender */
        $priceRender = $this->getLayout()->getBlock('product.price.render.default');

        $price = '';

        if ($priceRender) {
            $priceRender->setCacheLifetime(false);
            $priceType = $this->getPriceTypeCode($product->getTypeId());
            $arguments['zone'] = \Magento\Framework\Pricing\Render::ZONE_ITEM_LIST;
            $price = $priceRender->render($priceType, $product, $arguments);
        }

        return $price;
    }

    /**
     * @return Collection
     */
    private function getProductCollection()
    {
        /** @var Collection $collection */
        $collection = $this->collectionFactory->create();
        if ($productIds = $this->searchProductIds()) {
            if ($this->configProvider->isMysqlEngine()) {
                $collection->setVisibility($this->visibility->getVisibleInCatalogIds());
            }

            $collection->addIdFilter($productIds)
                ->addPriceData()
                ->addAttributeToSelect('name')
                ->addAttributeToSelect('image')
                ->addAttributeToSelect('allow_open_amount')
                ->addAttributeToSelect('open_amount_min')
                ->addAttributeToSelect('open_amount_max');
            $orderList = join(',', $productIds);
            $collection->getSelect()->order(
                sprintf('FIELD(e.entity_id, %s)', $orderList)
            );
        } else {
            $collection->getSelect()->where('null');
        }

        return $collection;
    }

    /**
     * @return array
     */
    /*
    private function searchProductIds()
    {
        $productIds = [];
        foreach ($this->search->search($this->getSearchCriteria())->getItems() as $item) {
            $productIds[] = $item->getId();
        }

        return $productIds;
    }
    */

    /**
     * @return SearchCriteriaInterface
     */
    /*
    private function getSearchCriteria()
    {
        return $this->searchCriteriaBuilder
            ->addSortOrder('relevance', 'desc')
            ->create()
            ->setRequestName(SearchInterface::CONTAINER_NAME);
    }
    */

    /*
    private function prepareStockFilter()
    {
        if ($this->configProvider->isElasticEngine()) {
            $this->addFilterToSearchCriteria('quickorder_stock_status', StockStatus::IN_STOCK);
        }
    }
    */
    

    /**
     * @param string $searchTerm
     * @throws InvalidArgumentException
     */
    private function prepareSearchTerm(string $searchTerm)
    {
        $searchTermLength = $this->stringUtils->strlen($searchTerm);

        if ($searchTermLength > $this->searchHelper->getMaxQueryLength()) {
            throw new InvalidArgumentException(
                __('Maximum Search query length is %1', $this->searchHelper->getMaxQueryLength())->__toString()
            );

        } elseif ($searchTermLength < $this->searchHelper->getMinQueryLength()) {
            throw new InvalidArgumentException(
                __('Minimum Search query length is %1', $this->searchHelper->getMinQueryLength())->__toString()
            );
        } else {
            $this->addFilterToSearchCriteria('search_term', $searchTerm);
        }
    }

    private function prepareVisibility()
    {
        $this->addFilterToSearchCriteria('visibility', $this->visibility->getVisibleInCatalogIds());
    }

    private function setLimit()
    {
        $this->searchCriteriaBuilder->setPageSize($this->configProvider->getSearchLimitResults());
    }

    /**
     * @param string $field
     * @param string|array $value
     */
    private function addFilterToSearchCriteria(string $field, $value)
    {
        $this->filterBuilder->setField($field);
        $this->filterBuilder->setValue($value);
        $this->searchCriteriaBuilder->addFilter($this->filterBuilder->create());
    }

    /**
     * @param string $typeId
     * @return string
     */
    protected function getPriceTypeCode(string $typeId): string
    {
        switch ($typeId) {
            case Bundle::TYPE_CODE:
                $priceCode = ConfiguredPrice::CONFIGURED_PRICE_CODE;
                break;
            case 'giftcard':
                $priceCode = 'quickorder_subtotal';
                break;
            default:
                $priceCode = FinalPrice::PRICE_CODE;
        }

        return $priceCode;
    }

    /**
     * @return \Magento\Framework\View\LayoutInterface
     */
    public function getLayout(): \Magento\Framework\View\LayoutInterface
    {
        if (!$this->layout) {
            $page = $this->resultPageFactory->create(false, ['isIsolated' => false]);
            $page->addHandle('catalog_category_view');
            $this->layout = $page->getLayout();
        }

        return $this->layout;
    }

}
