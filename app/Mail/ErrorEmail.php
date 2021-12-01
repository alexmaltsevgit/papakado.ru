<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ErrorEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $error;

    public function __construct(\Exception $error)
    {
        $this->error = $error;
    }

    /**
     * Build the message.
     *
     * @return $this
     */
    public function build()
    {
        return $this
            ->from( env('MAIL_FROM_ADDRESS') )
            ->subject('Ошибка системы доставки')
            ->view('mail.delivery.error', [
                'message' => $this->error->getMessage()
            ]);
    }

}
