<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Staff = 'staff';
    case Finance = 'finance';
    case ContentEditor = 'content_editor';
    // Technical role: everything an Admin can do, plus Developer tools (integrations, API, logs).
    case Developer = 'developer';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Staff => 'Staff',
            self::Finance => 'Finance',
            self::ContentEditor => 'Content Editor',
            self::Developer => 'Developer',
        };
    }
}
