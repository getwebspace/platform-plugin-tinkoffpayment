<?php declare(strict_types=1);

namespace Plugin\TinkoffPayment;

use App\Domain\Entities\Catalog\Order;
use App\Domain\Entities\Catalog\OrderProduct;
use App\Domain\Plugin\AbstractPaymentPlugin;
use Psr\Container\ContainerInterface;

class TinkoffPaymentPlugin extends AbstractPaymentPlugin
{
    const AUTHOR = 'Aleksey Ilyin';
    const AUTHOR_SITE = 'https://getwebspace.org';
    const NAME = 'TinkoffPaymentPlugin';
    const TITLE = 'TinkoffPayment';
    const DESCRIPTION = 'Возможность принимать безналичную оплату товаров и услуг';
    const VERSION = '1.0.1';

    public function __construct(ContainerInterface $container)
    {
        parent::__construct($container);

        $this->addSettingsField([
            'label' => 'Режим работы',
            'type' => 'select',
            'name' => 'mode',
            'args' => [
                'option' => [
                    'test' => 'Тестовый',
                    'prod' => 'Рабочий'
                ],
            ],
        ]);

        $this->addSettingsField([
            'label' => 'Терминал',
            'type' => 'text',
            'name' => 'login',
        ]);

        $this->addSettingsField([
            'label' => 'Пароль',
            'type' => 'text',
            'name' => 'password',
        ]);

        $this->addSettingsField([
            'label' => 'Description',
            'description' => 'В указанной строке <code>{serial}</code> заменится на номер заказа',
            'type' => 'text',
            'name' => 'description',
            'args' => [
                'value' => 'Оплата заказа #{serial}',
            ],
        ]);

        $this->addSettingsField([
            'label' => 'Налоговая ставка',
            'type' => 'select',
            'name' => 'tax',
            'args' => [
                'option' => [
                    'osn' => 'Общая СН',
                    'usn_income' => 'Упрощенная СН (доходы)',
                    'usn_income_outcome' => 'Упрощенная СН (доходы минус расходы)',
                    'envd' => 'Единый налог на вмененный доход',
                    'esn' => 'Единый сельскохозяйственный налог',
                    'patent' => 'Патентная СН',
                ],
            ],
        ]);

        // успешная оплата
        $this
            ->map([
                'methods' => ['get', 'post'],
                'pattern' => '/cart/done/tp/success',
                'handler' => \Plugin\TinkoffPayment\Actions\SuccessAction::class,
            ])
            ->setName('common:tp:success');

        // не успешная оплата
        $this
            ->map([
                'methods' => ['get', 'post'],
                'pattern' => '/cart/done/tp/error',
                'handler' => \Plugin\TinkoffPayment\Actions\ErrorAction::class,
            ])
            ->setName('common:tp:error');
    }

    public function getRedirectURL(Order $order): ?string
    {
        $this->logger->debug('TinkoffPayment: register order', ['serial' => $order->getSerial()]);

        $data = [
            'TerminalKey' => $this->parameter('TinkoffPaymentPlugin_login'),
            'Description' => mb_substr(str_replace('{serial}', $order->getSerial(), $this->parameter('TinkoffPaymentPlugin_description')), 0, 250),
            'OrderId' => $order->getSerial(),
            'Amount' => intval($order->getTotalPrice() * 100),
            'SuccessURL' => $this->parameter('common_homepage') . 'cart/done/tp/success?serial=' . $order->getSerial(),
            'FailURL' => $this->parameter('common_homepage') . 'cart/done/tp/error?serial=' . $order->getSerial(),
        ];

        // формирование чека
        $receipt = [
            'Phone' => $order->getPhone(),
            'Email' => $order->getEmail(),
            'Taxation' => $this->parameter('TinkoffPaymentPlugin_tax', 'osn'),

            'Items' => [],
        ];

        /** @var OrderProduct $product */
        foreach ($order->getProducts() as $product) {
            if ($product->getPrice()) {
                $receipt['Items'][] = [
                    'Name' => mb_substr($product->getTitle(), 0, 64),
                    'Price' => intval($product->getPrice() * 100),
                    'Quantity' => round($product->getCount(), 2),
                    'Amount' => intval($product->getTotal() * 100),
                    'PaymentMethod' => 'full_payment',
                    'Tax' => 'none',
                ];
            }
        }

        // подписание запроса и получение данных от ПС
        $result = $this->request('Init', array_merge($data, [
            'Token' => $this->getToken($data),

            'DATA' => [
                'Phone' => $order->getPhone(),
                'Email' => $order->getEmail(),
            ],

            'Receipt' => $receipt,
        ]));

        if ($result && ($result['Success'] === true || $result['ErrorCode'] === '0')) {
            return $result['PaymentURL'];
        }

        return null;
    }

    public function getToken(array $data): string
    {
        $data['Password'] = $this->parameter('TinkoffPaymentPlugin_password');
        ksort($data);

        return hash('sha256', implode('', array_values($data)));
    }

    public function request(string $method, array $data): mixed
    {
        switch ($this->parameter('TinkoffPaymentPlugin_mode', 'test')) {
            case 'prod': {
                $url = 'https://securepay.tinkoff.ru/v2/';
                break;
            }
            default:
            case 'test': {
                $url = 'https://rest-api-test.tinkoff.ru/v2/';
            }
        }
        $url = "{$url}{$method}";

        $result = @file_get_contents($url, false, stream_context_create([
            'ssl' => [
                "verify_peer" => false,
                "verify_peer_name" => false,
            ],
            'http' => [
                'method' => 'POST',
                'header' => 'Content-type: application/json',
                'content' => json_encode($data, JSON_UNESCAPED_UNICODE),
                'timeout' => 15,
            ],
        ]));

        // $this->logger->debug('TinkoffPayment: request', ['url' => $url, 'data' => $data]);
        // $this->logger->debug('TinkoffPayment: response', ['headers' => $http_response_header, 'response' => $result]);

        if ($result) {
            return json_decode($result, true);
        }

        return false;
    }
}
