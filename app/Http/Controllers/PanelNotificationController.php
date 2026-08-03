<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PanelNotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $notifications = $user->notifications()->latest()->limit(30)->get()->map(fn ($notification) => [
            'id' => $notification->id,
            'title' => data_get($notification->data, 'title', 'Notificación'),
            'message' => data_get($notification->data, 'message'),
            'level' => data_get($notification->data, 'level', 'info'),
            'icon' => data_get($notification->data, 'icon', 'ki-notification-status'),
            'url' => data_get($notification->data, 'url', '/'),
            'read' => $notification->read_at !== null,
            'created_at' => $notification->created_at?->toIso8601String(),
        ]);

        return response()->json([
            'notifications' => $notifications,
            'unread' => $user->unreadNotifications()->count(),
        ]);
    }

    public function read(Request $request, string $notification): JsonResponse
    {
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $item->markAsRead();

        return response()->json(['ok' => true]);
    }

    public function readAll(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->json(['ok' => true, 'unread' => 0]);
    }
}
