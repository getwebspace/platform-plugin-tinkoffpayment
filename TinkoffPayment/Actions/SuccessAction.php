<?php declare(strict_types=1);

namespace Plugin\TinkoffPayment\Actions;

use App\Application\Actions\Cup\Catalog\CatalogAction;
use Plugin\TinkoffPayment\TinkoffPaymentPlugin;

class SuccessAction extends CatalogAction
{
    protected function action(): \Slim\Psr7\Response
    {
        $default = [
            'serial' => '',
        ];
        $data = array_merge($default, $this->request->getQueryParams());

        // $this->logger->debug('TinkoffPayment: check', ['data' => $data]);

        $order = $this->catalogOrderService->read(['serial' => $data['serial']]);

        if ($order) {
            /** @var TinkoffPaymentPlugin $tp */
            $tp = $this->container->get('TinkoffPaymentPlugin');

            $data = [
                'TerminalKey' => $this->parameter('TinkoffPaymentPlugin_login'),
                'OrderId' => $order->getSerial(),
            ];
            $data['Token'] = $tp->getToken($data);
            $result = $tp->request('CheckOrder', $data);

            if ($result && ($result['Success'] === true || $result['ErrorCode'] === '0')) {
                $this->catalogOrderService->update($order, ['system' => 'Заказ оплачен']);
                $this->container->get(\App\Application\PubSub::class)->publish('plugin:order:payment', $order);
            } else {
                $this->catalogOrderService->update($order, ['system' => 'Заказ не был оплачен']);
            }

            return $this->respondWithRedirect('/cart/done/' . $order->getUuid()->toString());
        }

        return $this->respondWithRedirect('/');
    }
}
