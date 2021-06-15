<?php

namespace App\Controller;

use App\Entity\Flight;
use App\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mailer\MailerInterface;

class ApiController extends AbstractController
{
    private $orderRepository;

    /**
     * Забронировать место в рейсе или купить билет
     *
     * @Route("/api/v1/callback/events/flight/{flight}/seat/{seat}/reserve", name="reserve", requirements={"flight" = "\d+", "seat" = "\d{1,3}"})
     * @Route("/api/v1/callback/events/flight/{flight}/seat/{seat}/buy", name="buy", requirements={"flight" = "\d+", "seat" = "\d{1,3}"})
     */
    public function processOrder(?Flight $flight, int $seat, Request $request, EntityManagerInterface $entityManager): Response
    {
        $routeName = $request->attributes->get('_route');
        $data = [
                    'data' => [
                        'flight_id' => $flight ? $flight->getId() : null,
                        'seat_number' => $seat,
                        'triggered_at' => time(),
                        'secret_key' => $this->getSecretKey(),
                    ],
                ];

        if (!$flight) {
            $data['data']['event'] = 'error_no_flight';

            return $this->json($data, 404);
        }

        if (!$this->userIsGranted()) {
            $data['data']['event'] = 'error_user_has_no_rights';

            return $this->json($data, 403);
        }

        if ($seat > Order::SEATS_PER_FLIGHT) {
            $data['data']['event'] = 'error_seat_is_out_of_range';

            return $this->json($data, 400);
        }

        if (Flight::CANCELED == $flight->getStatus()) {
            $data['data']['event'] = 'flight_canceled';

            return $this->json($data);
        }

        if (Flight::STOPPED == $flight->getStatus()) {
            $data['data']['event'] = 'flight_ticket_sells_stopped';

            return $this->json($data);
        }

        $orders = $this->getDoctrine()->getRepository(Order::class)->findByFlight($flight);
        if (count($orders) == Order::SEATS_PER_FLIGHT) {
            $data['data']['event'] = 'no_seats_available' . count($orders);

            return $this->json($data);
        }

        $order = $this->getDoctrine()->getRepository(Order::class)->findByFlightAndSeat($flight, $seat);
        if ($order) {
            $data['data']['event'] = 'error_seat_is_occupied';

            return $this->json($data, 400);
        }

        $order = new Order();
        $order
            ->setFlight($flight)
            ->setSeatNumber($seat)
            ->setUserEmail($this->getUserEmail());

        if ('reserve' == $routeName) {
            $event = 'flight_ticket_reserve_completed';
            $order->setOrderStatus(Order::RESERVED);
        } else {
            $event = 'flight_ticket_sale_completed';
            $order->setOrderStatus(Order::SOLD);
        }

        $entityManager->persist($order);
        $entityManager->flush();

        if ($order_id = $order->getId()) {
            $data['data']['event'] = $event;
            $data['data']['order_id'] = $order_id;

            return $this->json($data);
        }

        $data['data']['event'] = 'unable_to_perform';

        return $this->json($data, 520);
    }

    /**
     * Отменить бронь или возвратить купленный билет
     *
     * @Route("/api/v1/callback/events/order/{order}/cancel", name="cancelReserve", requirements={"order" = "\d+"})
     * @Route("/api/v1/callback/events/order/{order}/refund", name="refund", requirements={"order" = "\d+"})
     */
    public function cancelOrder(?Order $order, Request $request, EntityManagerInterface $entityManager): Response
    {
        if (!$order) {
            $data['data']['event'] = 'error_no_order';

            return $this->json($data, 404);
        }
        $is_reserve = 'cancelReserve' == $request->attributes->get('_route'); //работа с резервом
        $data = [
                    'data' => [
                        'order_id' => $order->getId(),
                        'triggered_at' => time(),
                        'secret_key' => $this->getSecretKey(),
                    ],
                ];
        if (!$this->userIsGranted()) {
            $data['data']['event'] = 'error_user_has_no_rights';

            return $this->json($data, 403);
        }
        if ($is_reserve) { //если резерв
            if ($order->isReserve()) {
                $entityManager->remove($order);
                $entityManager->flush();
                $data['data']['event'] = 'fligth_ticket_reservation_cancel_complete';
            } else {
                $data['data']['event'] = 'is_not_reserve';
            }
        } else { //если билет куплен
            if (!$order->isReserve()) {
                $this->refund($order); //вернуть средства

                $entityManager->remove($order);
                $entityManager->flush();

                $data['data']['event'] = 'fligth_ticket_refund_complete';
            } else {
                $data['data']['event'] = 'is_reserve';
            }
        }

        return $this->json($data);
    }

    /**
     * Установить статус рейса
     *
     * @Route("/api/v1/callback/events/flight/{flight}/status/{status}", name="cancelFlight", requirements={"flight" = "\d+", "status" = "[CS]{1}"})
     */
    public function setFlightStatus(?Flight $flight, string $status, Request $request, EntityManagerInterface $entityManager, MailerInterface $mailer): Response
    {
        $data = [
                    'data' => [
                        'flight_id' => $flight ? $flight->getId() : null,
                        'triggered_at' => time(),
                        'secret_key' => $this->getSecretKey(),
                    ],
                ];

        if (!$flight) {
            $data['data']['event'] = 'error_no_flight';

            return $this->json($data, 404);
        }

        if (!$this->userIsGranted()) {
            $data['data']['event'] = 'error_user_has_no_rights';

            return $this->json($data, 403);
        }
        
        if ($flight->getStatus()) {
            $data['data']['event'] = 'error_flight_unavailable';

            return $this->json($data, 400);
        }

        $flight->setStatus($status);
        $entityManager->flush();

        if (Flight::CANCELED == $status) { //при отмене рейса
            $data['data']['notified'] = [];
            $orders = $this->getDoctrine()->getRepository(Order::class)->findByFlight($flight);
            foreach ($orders as $order) {
                if (!$order->isReserve()) {
                    $this->refund($order); //вернуть средства
                }
                $this->notifyByEmail($order->getUserEmail(), $order->getOrderStatus(), $flight->getId(), $mailer);
                $data['data']['notified'][] = ['order_id' => $order->getId(), 'user_email' => $order->getUserEmail()];
                $entityManager->remove($order);
                $entityManager->flush();
            }
            $data['data']['event'] = 'flight_canceled';
        }
        if (Flight::STOPPED == $status) { //при остановке продаж билетов на рейс
            $data['data']['event'] = 'flight_ticket_sales_stopped';
        }
        $data['data']['finished_at'] = time();

        return $this->json($data);
    }
    

    /**
     * Заполнить данными
     *
     * @Route("/api/v1/callback/events/fill", name="fill")
     */
    public function fillBase(EntityManagerInterface $entityManager) {
        for ($i=0; $i<15; $i++) {
            $flight = new Flight;
            $flight->setNumber('FLV' . rand(100, 999));
            $entityManager->persist($flight);
            $entityManager->flush();
            $seats = rand(Order::SEATS_PER_FLIGHT - 30, Order::SEATS_PER_FLIGHT + 10);
            for ($s=1; $s < $seats; $s++) {
                if ($s > Order::SEATS_PER_FLIGHT) break;
                $order = new Order;
                $order
                    ->setFlight($flight)
                    ->setSeatNumber($s)
                    ->setUserEmail($this->getUserEmail())
                    ->setOrderStatus(rand(0,1) ? Order::RESERVED : Order::SOLD) ;
                $entityManager->persist($order);
            }
            $entityManager->flush();
        }
        return $this->json(["data" => ["event" => "completed"]]);
    }        


    /**
     * Возврат денежных средств.
     */
    public function refund(order $order)
    {
        //вызов обработчика возврата средств
        return;
    }

    /**
     * Сформировать secret_key.
     */
    private function getSecretKey(): string
    {
        return 'a1b2c3d4e5f6a1b2c3d4e5f6'; //пусть такой
    }

    /**
     * email пользователя.
     */
    private function getUserEmail(): string
    {
        return 'mail'.rand(100, 999).'@postbox.com'; //пусть заполняется так
    }

    /**
     * Проверка прав пользователя.
     */
    private function userIsGranted(): bool
    {
        /*
        здесь выполняется проверка прав пользователя на операции с заказами - реализация не требуется в рамках ТЗ
        */
        return true;
    }

    /**
     * Уведомление по почте.
     * сообщения отправляются ассинхронно через messenger (см. messenger.yaml)
     */
    private function notifyByEmail($email, $status, $flight_id, $mailer)
    {
        $text = ($status == Order::RESERVED) ? $this->getParameter('app.MAIL_TEXT') : $this->getParameter('app.MAIL_REFUND_TEXT');
        $email = (new Email())
            ->from($this->getParameter('app.MAIL_FROM'))
            ->to($email)
            ->subject(str_replace("flight_id", $flight_id, $this->getParameter('app.MAIL_SUBJ')))
            ->text($text);
        //$mailer->send($email); //отправка отключена
        return true;
    }
}
