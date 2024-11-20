<?php

namespace App\Services;

use App\Models\AddressOrder;
use App\Models\Basket;
use App\Models\Bundles;
use App\Models\Counterparty;
use App\Models\CounterPartyStatus;
use App\Models\Order;
use App\Models\OrderStatus;
use App\Models\Prices;
use App\Models\Product;
use App\Models\ProductsBundles;
use App\Models\ProductType;
use App\Models\Region;
use App\Models\Stock;
use App\Models\Stores;

use RuntimeException;

use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MoySkladService
{
    /**
     * @return string
     */
    public static function getPrivateKey(): string
    {
        return self::$privateKey;
    }

    /**
     * @return string
     */
    public static function getBaseUrl(): string
    {
        return self::$baseUrl;
    }

    /**
     * @return array
     */
    public static function getExcludeEntityFilter(): array
    {
        return self::$excludeEntityFilter;
    }

    /**
     * @return int
     */
    public static function getTimeoutRequest(): int
    {
        return self::$timeoutRequest;
    }

    /**
     * @return int
     */
    public static function getDaysRangeAgo(): int
    {
        return self::$daysRangeAgo;
    }

    private static int $limit = 1000;

    /**
     * @return int
     */
    public static function getLimit(): int
    {
        return self::$limit;
    }

    /**
     * @param int $limit
     */
    public static function setLimit(int $limit): void
    {
        self::$limit = $limit;
    }

    /**
     * @return int
     */
    public static function getOffset(): int
    {
        return self::$offset;
    }

    /**
     * @param int $offset
     */
    public static function setOffset(int $offset): void
    {
        self::$offset = $offset;
    }

    private static int $offset = 0;

    private static string $privateKey;
    private static string $baseUrl;
    private static array $excludeEntityFilter;
    private static int $timeoutRequest;
    private static int $daysRangeAgo;

    private function __construct()
    {
        static::$privateKey = config('app.moy_sklad');

        static::$baseUrl = config('app.base_url_api_moy_sklad');
        static::$excludeEntityFilter = explode(',', config('app.name_sync_entity_for_full_load'));
        static::$timeoutRequest = config('app.time_out_between_request');
        static::$daysRangeAgo = config('app.days_update_interval');
    }

    public static function getUri(string $path): string
    {
        return sprintf('%s%s', static::$baseUrl, $path);
    }

    public static function getMethodPath(string $type, array $params = []): string
    {
        return match ($type) {
            'catalog' => '/entity/assortment',
            'orders' => '/entity/customerorder',
            'basket' => "/entity/customerorder/{$params['order_id']}/positions",
            'stores' => '/entity/store',
            'status' => '/entity/customerorder/metadata',
            'counterparty' => '/entity/counterparty',
            'counterparty_status' => '/entity/counterparty/metadata',
            'stocks' => '/report/stock/bystore',
            'bundles' => '/entity/bundle',
            'components' => "/entity/bundle/{$params['bundle_id']}/components",
            'regions' => '/entity/region',
            default => throw new RuntimeException('Parameter type is mandatory, input type is not support!'),
        };
    }

    public static function getHttpClient(): PendingRequest
    {
        $http = Http::withHeaders(
            [
                'Authorization' => sprintf('Bearer %s', static::$privateKey),
                'Accept-Encoding' => 'gzip',
            ],
        );

        $http
            ->connectTimeout(30)
            ->retry(5, 5000);

        return $http;
    }

    public static function getParamsRequest(string $type): array
    {
        $params = [
            'limit' => self::getLimit(),
            'offset' => self::getOffset(),
            'filter' => sprintf(
                'updated>%s',
                Carbon::now()
                    ->subDays(static::getDaysRangeAgo())
                    ->format('Y-m-d H:i:s.v'),
            ),
        ];

        if (
            in_array(
                $type,
                self::$excludeEntityFilter,
                true,
            )
        ) {
            unset($params['filter']);
        }

        return $params;
    }

    private static function getValueAttributeById(
        array  $params,
        string $attrId,
    ): string
    {
        $item = array_filter(
            $params['attributes'] ?? [],
            static function (mixed $item) use ($attrId) {
                return $item['id'] === $attrId;
            },
        );

        return current($item)['value']['name'] ?? '';
    }

    public static function processStoreData(array $data): void
    {
        if (empty($data['rows'])) {
            return;
        }

        foreach ($data['rows'] as $store) {
            Stores::query()->updateOrInsert(
                [
                    'ext_id' => $store['id'],
                ],
                [
                    'name' => $store['name'],
                    'address' => $store['address'] ?? '',
                    'ext_code' => $store['externalCode'],
                    'updated_at' => Carbon::parse($store['updated']),
                ],
            );
        }
    }

    public static function processRegionData(array $data): void
    {
        if (empty($data['rows'])) {
            return;
        }

        foreach ($data['rows'] as $region) {
            Region::query()->updateOrInsert(
                [
                    'ext_id' => $region['id'],
                ],
                [
                    'name' => $region['name'],
                    'code' => $region['code'],
                    'ext_code' => $region['externalCode'],
                    'updated_at' => Carbon::parse($region['updated']),
                ],
            );
        }
    }

    public static function processDictData(array $data, string $type): void
    {
        if (empty($data['states'])) {
            return;
        }

        /**
         * @var Model $model
         */
        $model = match ($type) {
            'status' => OrderStatus::class,
            'counterparty_status' => CounterPartyStatus::class,
        };

        foreach ($data['states'] as $states) {
            $model::query()->updateOrInsert(
                [
                    'ext_id' => $states['id'],
                ],
                [
                    'name' => $states['name'],
                    'color' => $states['color'],
                    'state_type' => $states['stateType'],
                ],
            );
        }
    }

    public static function processStocksData(array $data): void
    {
        if (empty($data['rows'])) {
            return;
        }

        foreach ($data['rows'] as $stock) {
            $part = parse_url($stock['meta']['href']);
            $productId = static::extractGuidFromUri($part['path']);

            array_map(
                static function (mixed $item) use ($productId) {
                    $storeId = static::extractGuidFromUri($item['meta']['href']);

                    Stock::query()->updateOrInsert(
                        [
                            'product_id' => $productId,
                            'store_id' => $storeId,
                        ],
                        [
                            'store_name' => $item['name'],
                            'stock' => $item['stock'] ?? .0,
                            'reserve' => $item['reserve'] ?? .0,
                            'in_transit' => $item['inTransit'] ?? .0,
                            'updated_at' => Carbon::now(),
                        ],
                    );
                },
                $stock['stockByStore'],
            );
        }
    }

    public static function processCounterpartyData(array $data): void
    {
        if (empty($data['rows'])) {
            return;
        }

        foreach ($data['rows'] as $counterparty) {
            $statusId = isset($counterparty['state'])
                ? static::extractGuidFromUri($counterparty['state']['meta']['href'])
                : '';

            Counterparty::query()->updateOrInsert(
                [
                    'ext_id' => $counterparty['id'],
                ],
                [
                    'status_id' => $statusId,

                    'name' => $counterparty['name'],
                    'description' => $counterparty['description'] ?? '',
                    'phone' => $counterparty['phone'] ?? '',
                    'email' => $counterparty['email'] ?? '',
                    'legal_middle_name' => $counterparty['legalMiddleName'] ?? '',
                    'legal_first_name' => $counterparty['legalFirstName'] ?? '',
                    'legal_last_name' => $counterparty['legalLastName'] ?? '',
                    'ogrnip' => $counterparty['ogrnip'] ?? '',
                    'ogrn' => $counterparty['ogrn'] ?? '',
                    'okpo' => $counterparty['okpo'] ?? '',
                    'inn' => $counterparty['inn'] ?? '',
                    'actual_address' => $counterparty['actualAddress'] ?? '',
                    'legal_address' => $counterparty['legalAddress'] ?? '',
                    'company_name' => $counterparty['legalTitle'] ?? '',
                    'company_type' => $counterparty['companyType'],
                    'ext_code' => $counterparty['externalCode'],
                    'sales_amount' => $counterparty['salesAmount'] ?? .0,
                    'tags' => $counterparty['tags']
                        ? implode(',', $counterparty['tags']) : '',

                    'updated_at' => Carbon::parse($counterparty['updated']),
                    'created_at' => Carbon::parse($counterparty['created']),
                ],
            );
        }
    }

    public static function processOrderData(array $data, PendingRequest $request): void
    {
        if (empty($data['rows'])) {
            return;
        }

        foreach ($data['rows'] as $order) {
            $countPosition = $order['positions']['meta']['size'] ?? 0;

            $storeId = isset($order['store'])
                ? static::extractGuidFromUri($order['store']['meta']['href'])
                : '';

            $customerId = isset($order['agent'])
                ? static::extractGuidFromUri($order['agent']['meta']['href'])
                : '';

            $statusId = isset($order['state'])
                ? static::extractGuidFromUri($order['state']['meta']['href'])
                : '';

            $paymentType = static::getValueAttributeById(
                params: $order,
                attrId: '4c61c702-d33f-11eb-0a80-093100099c0d',
            );

            $deliveryType = static::getValueAttributeById(
                params: $order,
                attrId: '15fd32b9-d056-11eb-0a80-0dc400121910',
            );

            $customerType = static::getValueAttributeById(
                params: $order,
                attrId: 'c8a630e2-d057-11eb-0a80-01ef0011bcc2',
            );

            $hasClosedDocuments = static::getValueAttributeById(
                params: $order,
                attrId: 'b90ce931-b2f8-11eb-0a80-026100064a82',
            );

            $siteId = static::getValueAttributeById(
                params: $order,
                attrId: '0bc6e799-6466-11ef-0a80-0cfe000750fc',
            );

            $sourceId = static::getValueAttributeById(
                params: $order,
                attrId: '9b1b27d9-6466-11ef-0a80-138d0011b5fa',
            );

            $orderSource = static::getValueAttributeById(
                params: $order,
                attrId: 'e2cf94e3-5fcf-11ef-0a80-00170017b1c2',
            );

            $reasonCancel = static::getValueAttributeById(
                params: $order,
                attrId: '0f637ae8-6315-11ef-0a80-11d8002e6aa1',
            );

            Order::query()->updateOrInsert(
                [
                    'ext_id' => $order['id'],
                ],
                [
                    'order_number' => $order['name'],
                    'ext_code' => $order['externalCode'],
                    'code' => $order['code'] ?? '',
                    'goods_count' => $countPosition,

                    'order_price' => $order['sum'] / 100,
                    'paid_sum' => $order['payedSum'] / 100,
                    'shipped_sum' => $order['shippedSum'] / 100,
                    'invoiced_sum' => $order['invoicedSum'] / 100,

                    'store_id' => $storeId,
                    'customer_id' => $customerId,
                    'status_id' => $statusId,
                    // attributes of order
                    'payment_type' => $paymentType,
                    'delivery_type' => $deliveryType,
                    'customer_type' => $customerType,
                    'has_closed_documents' => $hasClosedDocuments ? 1 : 0,

                    'site_id' => $siteId,
                    'source_id' => $sourceId,
                    'order_source' => $orderSource,
                    'reason_cancel' => $reasonCancel,

                    'updated_at' => Carbon::parse($order['updated']),
                    'created_at' => Carbon::parse($order['created']),
                ],
            );

            if (
                !empty($order['shipmentAddress'])
                && !empty($order['shipmentAddressFull'])
            ) {
                $regionId = isset($order['shipmentAddressFull']['region'])
                    ? static::extractGuidFromUri($order['shipmentAddressFull']['region']['meta']['href'])
                    : '';

                AddressOrder::query()->updateOrInsert(
                    [
                        'order_id' => $order['id'],
                    ],
                    [
                        'full_address' => $order['shipmentAddress'],
                        'city' => $order['shipmentAddressFull']['city'] ?? '',
                        'region_id' => $regionId,
                    ],
                );
            }

            if (empty($countPosition)) {
                continue;
            }

            //делаем паузу, что бы не спамить сервис, 3 сек
            usleep(self::$timeoutRequest * 1000);

            $path = static::getMethodPath('basket', ['order_id' => $order['id']]);

            $response = $request->get(
                static::getUri($path),
                [
                    'limit' => 300,
                    'offset' => 0,
                ],
            );

            if ($response->status() === 429) {
                Log::error(
                    'Service response with 429, wait 10 sec',
                    ['order_id' => $order['id']],
                );
                sleep(10);
            }

            $basket = $response->json();

            array_map(
                static function (mixed $item) use ($order) {
                    $productId = isset($item['assortment'])
                        ? static::extractGuidFromUri($item['assortment']['meta']['href'])
                        : '';

                    $isProduct = str_contains($item['assortment']['meta']['href'], 'product');
                    $isBundle = str_contains($item['assortment']['meta']['href'], 'bundle');

                    if (!$isProduct && !$isBundle) {
                        $productId = '';
                    }

                    $type = '';

                    if ($isBundle) {
                        $type = ProductType::Bundle->value;
                    }

                    if ($isProduct) {
                        $type = ProductType::Product->value;
                    }

                    if (!empty($productId)) {
                        $product = match ($type) {
                            ProductType::Product->value => Product::query()
                                ->where('ext_id', '=', $productId)
                                ->get(['buy_price'])
                                ->first()?->toArray() ?? [],
                            ProductType::Bundle->value => [
                                'buy_price' => static::getBundleBuyPrice($productId),
                            ],
                            default => [],
                        };
                    }

                    Log::info(
                        'order position',
                        [
                            'order' => $order['id'],
                            'product' => $productId,
                            'price' => $item['price'] / 100,
                            'buy_price' => $product['buy_price'] ?? 0,
                        ],
                    );

                    Basket::query()->updateOrInsert(
                        [
                            'ext_id' => $item['id'],
                        ],
                        [
                            'order_id' => $order['id'],
                            'product_id' => $productId,
                            'count' => (int)$item['quantity'],
                            'shipped' => (int)$item['shipped'],
                            'buy_price' => $product['buy_price'] ?? .0,
                            'product_type' => $type,
                            'sale_price' => $item['price']
                                ? ((float)$item['price'] / 100) : .0,
                            'discount' => $item['discount'] ?? .0,
                            'updated_at' => Carbon::parse($order['updated']),
                        ],
                    );
                },
                $basket['rows'] ?? [],
            );
        }
    }

    public static function processBundlesData(array $data, PendingRequest $request): void
    {

        if (empty($data['rows'])) {
            return;
        }

        foreach ($data['rows'] as $bundle) {
            $brand = static::getValueAttributeById(
                params: $bundle,
                attrId: '0076b518-d9b3-11eb-0a80-06ae0011d1aa',
            );

            $folderId = isset($bundle['productFolder'])
                ? static::extractGuidFromUri($bundle['productFolder']['meta']['href'])
                : '';

            $bcodes = [];

            foreach ($bundle['barcodes'] ?? [] as $bcode) {
                $key = current(array_keys($bcode));
                $value = current(array_values($bcode));
                $bcodes[$key] = $value;
            }

            Bundles::query()->updateOrInsert(
                [
                    'ext_id' => $bundle['id'],
                ],
                [
                    'name' => $bundle['name'],
                    'group_id' => $folderId,
                    'code' => $bundle['code'],
                    'ext_code' => $bundle['externalCode'],
                    'article' => $bundle['article'] ?? '',
                    'ean13' => $bcodes['ean13'] ?? '',
                    'gtin' => $bcodes['gtin'] ?? '',
                    'group_name' => $bundle['pathName'] ?? '',
                    'brand' => $brand,
                    'updated_at' => Carbon::parse($bundle['updated']),
                ],
            );

            array_map(
                static function (mixed $item) use ($bundle) {
                    Prices::query()->updateOrInsert(
                        [
                            'product_id' => $bundle['id'],
                            'type_id' => $item['priceType']['id'],
                        ],
                        [
                            'type_name' => $item['priceType']['name'],
                            'value' => $item['value'] / 100,
                        ],
                    );
                },
                $bundle['salePrices'],
            );

            usleep(self::$timeoutRequest * 1000);

            $path = static::getMethodPath('components', ['bundle_id' => $bundle['id']]);

            $response = $request->get(
                static::getUri($path),
                [
                    'limit' => 50,
                    'offset' => 0,
                ],
            );

            if ($response->status() === 429) {
                Log::error(
                    'Service response with 429, wait 10 sec',
                    ['bundle_id' => $bundle['id']],
                );
                sleep(10);
            }

            $components = $response->json();

            array_map(
                static function (mixed $item) use ($bundle) {
                    ProductsBundles::query()->updateOrInsert(
                        [
                            'ext_id' => $item['id'],
                        ],
                        [
                            'bundle_id' => $bundle['id'],
                            'product_id' => static::extractGuidFromUri($item['assortment']['meta']['href'] ?? ''),
                            'quantity' => (int)$item['quantity'],
                        ],
                    );
                },
                $components['rows'] ?? [],
            );
        }
    }

    public static function processCatalogData(array $data): void
    {
        if (empty($data['rows'])) {
            return;
        }
        //@todo ускорить синхронизацию, путем анализа даты обновления, брать с запасом, - 2 суток от даты старта скрипта
        foreach ($data['rows'] as $product) {
            $brand = static::getValueAttributeById(
                params: $product,
                attrId: '0076b518-d9b3-11eb-0a80-06ae0011d1aa',
            );

            $status = static::getValueAttributeById(
                params: $product,
                attrId: '79075eb4-5557-11ef-0a80-07340020fca4',
            );

            $folderId = isset($product['productFolder'])
                ? static::extractGuidFromUri($product['productFolder']['meta']['href'])
                : '';

            $supplierId = isset($product['supplier'])
                ? static::extractGuidFromUri($product['supplier']['meta']['href'])
                : '';

            $bcodes = [];

            foreach ($product['barcodes'] ?? [] as $bcode) {
                $key = current(array_keys($bcode));
                $value = current(array_values($bcode));
                $bcodes[$key] = $value;
            }

            //@todo реализовать работу с бандлами

            Product::query()->updateOrInsert(
                [
                    'ext_id' => $product['id'],
                ],
                [
                    'name' => $product['name'],
                    'group_id' => $folderId,
                    'code' => $product['code'],
                    'ext_code' => $product['externalCode'],
                    'article' => $product['article'] ?? '',
                    'status' => $status,
                    'buy_price' => isset($product['buyPrice'])
                        ? $product['buyPrice']['value'] / 100
                        : 0,
                    'ean13' => $bcodes['ean13'] ?? '',
                    'gtin' => $bcodes['gtin'] ?? '',
                    'group_name' => $product['pathName'] ?? '',
                    'supplier_id' => $supplierId,
                    'has_images' => isset($product['images'])
                        && $product['images']['meta']['size'] > 0,
                    'brand' => $brand,
                    'stock' => $product['stock'] ?? 0,
                    'reserve' => $product['reserve'] ?? 0,
                    'quantity' => $product['quantity'] ?? 0,
                    'updated_at' => Carbon::parse($product['updated']),
                ],
            );

            array_map(
                static function (mixed $item) use ($product) {
                    Prices::query()->updateOrInsert(
                        [
                            'product_id' => $product['id'],
                            'type_id' => $item['priceType']['id'],
                        ],
                        [
                            'type_name' => $item['priceType']['name'],
                            'value' => $item['value'] / 100,
                        ],
                    );
                },
                $product['salePrices'],
            );
        }
    }

    public static function getBundleBuyPrice(string $bundleId): float
    {
        $products = ProductsBundles::query()
            ->where('bundle_id', '=', $bundleId)
            ->get(['product_id', 'quantity'])
            ->toArray();

        return array_reduce(
            $products,
            static function ($accum, mixed $item) {
                $product = Product::query()
                    ->where('ext_id', '=', $item['product_id'])
                    ->get(['buy_price'])
                    ->first()?->toArray();

                return $accum + (($product['buy_price'] ?? .0) * $item['quantity']);
            },
            .0,
        );
    }

    public static function extractGuidFromUri(string $uri): string
    {
        return last(explode('/', $uri)) ?: '';
    }

    public static function create(): self
    {
        return new self();
    }
}
