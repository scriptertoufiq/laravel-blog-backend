<?php

namespace App\Notifications\Blog;

use App\Models\Comment;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class PostCommentedOn extends Notification
{
    public function __construct(public Comment $comment) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  User  $notifiable
     */
    public function toMail(object $notifiable): MailMessage
    {
        $post = $this->comment->post;

        return (new MailMessage)
            ->subject(__('New comment on ":title"', ['title' => $post->title]))
            ->greeting(__('Hello :name!', ['name' => $notifiable->name]))
            ->line(__(':author commented on your post ":title".', [
                'author' => $this->comment->author?->name ?? __('Someone'),
                'title' => $post->title,
            ]))
            ->line('"'.Str::limit($this->comment->body, 200).'"')
            ->action(__('Read the post'), route('api.v1.posts.show', $post->slug));
    }
}
