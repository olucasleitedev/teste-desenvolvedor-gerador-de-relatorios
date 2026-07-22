<?php

namespace App\Services;

use App\Models\ReportExport;
use Illuminate\Support\Facades\Storage;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use RuntimeException;
use Throwable;

class ReportExportQueue
{
    public function publish(ReportExport $export): void
    {
        if (config('reports.export_driver') === 'sync') {
            app(ReportExportProcessor::class)->process($export);

            return;
        }

        [$connection, $channel] = $this->openChannel();

        try {
            $channel->queue_declare($this->queueName(), false, true, false, false);

            $message = new AMQPMessage(
                json_encode(['export_id' => $export->id], JSON_THROW_ON_ERROR),
                ['delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT],
            );

            $channel->basic_publish($message, '', $this->queueName());
        } finally {
            $channel->close();
            $connection->close();
        }
    }

    public function consume(callable $handler): void
    {
        [$connection, $channel] = $this->openChannel();

        $channel->queue_declare($this->queueName(), false, true, false, false);
        $channel->basic_qos(null, 1, null);

        $channel->basic_consume(
            $this->queueName(),
            '',
            false,
            false,
            false,
            false,
            function (AMQPMessage $message) use ($handler, $channel): void {
                try {
                    $handler($message);
                    $channel->basic_ack($message->getDeliveryTag());
                } catch (Throwable $exception) {
                    $channel->basic_nack($message->getDeliveryTag(), false, false);
                    report($exception);
                }
            },
        );

        while ($channel->is_consuming()) {
            $channel->wait();
        }

        $channel->close();
        $connection->close();
    }

    public function decodeExportId(AMQPMessage $message): string
    {
        $payload = json_decode($message->getBody(), true, 512, JSON_THROW_ON_ERROR);
        $exportId = $payload['export_id'] ?? null;

        if (! is_string($exportId) || $exportId === '') {
            throw new RuntimeException('Invalid report export message payload.');
        }

        return $exportId;
    }

    private function openChannel(): array
    {
        $config = config('reports.rabbitmq');

        $connection = new AMQPStreamConnection(
            $config['host'],
            $config['port'],
            $config['user'],
            $config['password'],
            $config['vhost'],
        );

        return [$connection, $connection->channel()];
    }

    private function queueName(): string
    {
        return config('reports.rabbitmq.queue');
    }
}
