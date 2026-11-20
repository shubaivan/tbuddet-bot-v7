<?php

declare(strict_types=1);

namespace App\Controller;

use App\Invoice\InvoicePublicLink;
use App\Invoice\InvoiceRequisitesMissingException;
use App\Invoice\InvoiceService;
use App\Repository\UserOrderRepository;
use App\Service\Payment\OrderOnlinePayment;
use App\Service\Payment\PaymentResultUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/** PDF рахунку за публічним (токенізованим) посиланням — без входу, лише читання. */
final class InvoicePublicController extends AbstractController
{
    #[Route('/invoice/{id}/{token}.pdf', name: 'invoice_public_pdf', methods: [Request::METHOD_GET], requirements: ['id' => '\d+', 'token' => '[A-Za-z0-9_-]{32}'])]
    public function pdf(
        int $id,
        string $token,
        Request $request,
        UserOrderRepository $orders,
        InvoicePublicLink $links,
        InvoiceService $invoices,
    ): Response {
        $order = $orders->find($id);
        // Однакова відповідь для «немає замовлення» і «невірний токен» — нічого не перебереш.
        if (null === $order || !$order->isInvoicePayment() || !$links->isValid($order, $token)) {
            return new Response('Рахунок не знайдено.', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Robots-Tag' => 'noindex']);
        }

        try {
            $pdf = $invoices->renderPdf($order);
        } catch (InvoiceRequisitesMissingException) {
            return new Response('Рахунок тимчасово недоступний. Зверніться до менеджера.', Response::HTTP_SERVICE_UNAVAILABLE, ['Content-Type' => 'text/plain; charset=UTF-8']);
        }

        $disposition = $request->query->getBoolean('download') ? 'attachment' : 'inline';

        return new Response($pdf, Response::HTTP_OK, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('%s; filename="Rahunok-%s.pdf"', $disposition, $order->getInvoiceNumber()),
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            // Сайт (artbeton.market) підтягує PDF для «Поділитися» файлом — посилання й так публічне.
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * «Оплатити онлайн» з рахунку (Telegram / email): щоразу свіжий LiqPay checkout
     * для ЦЬОГО ж замовлення. Оплачене / скасоване — без оплати, на сторінку статусу.
     */
    #[Route('/invoice/{id}/{token}/pay', name: 'invoice_public_pay', methods: [Request::METHOD_GET], requirements: ['id' => '\d+', 'token' => '[A-Za-z0-9_-]{32}'])]
    public function pay(
        int $id,
        string $token,
        UserOrderRepository $orders,
        InvoicePublicLink $links,
        OrderOnlinePayment $onlinePayment,
        PaymentResultUrl $paymentResultUrl,
    ): Response {
        $order = $orders->find($id);
        if (null === $order || !$order->isInvoicePayment() || !$links->isValid($order, $token)) {
            return new Response('Рахунок не знайдено.', Response::HTTP_NOT_FOUND, ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Robots-Tag' => 'noindex']);
        }

        if ($order->isPaid()) {
            return new RedirectResponse($paymentResultUrl->build('uk', (int) $order->getId()));
        }
        if (!OrderOnlinePayment::canPay($order)) {
            return new Response('Замовлення скасовано — оплата за ним більше не приймається. Зверніться до менеджера.', Response::HTTP_GONE, ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Robots-Tag' => 'noindex']);
        }

        return new RedirectResponse($onlinePayment->checkoutLink($order, 'uk'), Response::HTTP_FOUND, [
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
