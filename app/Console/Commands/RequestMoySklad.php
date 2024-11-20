<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use App\Services\MoySkladService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class RequestMoySklad extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'sync:moysklad {type}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Get Actual MoySklad Data Set';

    public function __construct(
        public MoySkladService $moysklad,
    )
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        if (!$this->argument('type')) {
            $this->error('Не передан обязательный параметр type, пример: catalog');

            return Command::FAILURE;
        }

        $path = $this->moysklad::getMethodPath($this->argument('type'));

        $http = $this->moysklad::getHttpClient();

        $start = Carbon::now()->getTimestamp();

        $response = $http->get(
            $this->moysklad::getUri($path),
            $this->moysklad::getParamsRequest($this->argument('type')),
        );

        if ($this->argument('type') === 'status') {
            $this->moysklad::processDictData($response->json(), 'status');

            return Command::SUCCESS;
        }

        if ($this->argument('type') === 'counterparty_status') {
            $this->moysklad::processDictData($response->json(), 'counterparty_status');

            return Command::SUCCESS;
        }

        $offset = $this->moysklad::getOffset();

        while ($response['meta']['size'] > $offset) {
            match ($this->argument('type')) {
                'catalog' => $this->moysklad::processCatalogData($response->json()),
                'orders' => $this->moysklad::processOrderData($response->json(), $http),
                'stores' => $this->moysklad::processStoreData($response->json()),
                'counterparty' => $this->moysklad::processCounterpartyData($response->json()),
                'stocks' => $this->moysklad::processStocksData($response->json()),
                'bundles' => $this->moysklad::processBundlesData($response->json(), $http),
                'regions' => $this->moysklad::processRegionData($response->json()),
                default => throw new RuntimeException('Parameter type is mandatory, input type is not support!'),
            };

            $offset += $this->moysklad::getLimit();
            $this->moysklad::setOffset($offset);

            $response = $http->get(
                $this->moysklad::getUri($path),
                $this->moysklad::getParamsRequest($this->argument('type')),
            );
        }

        Log::info(
            'Time spend on execution sync',
            [
                'type' => $this->argument('type'),
                'sec' => Carbon::now()->getTimestamp() - $start,
            ],
        );

        $this->info('Data set synchronized successfully!');

        return Command::SUCCESS;
    }
}
