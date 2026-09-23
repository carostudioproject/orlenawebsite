<?php

namespace App\Enums;

enum Role: string
{
    case Admin = 'admin';
    case Staff = 'staff';
    case Finance = 'finance';
    case ContentEditor = 'content_editor';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Staff => 'Staff',
            self::Finance => 'Finance',
            self::ContentEditor => 'Content Editor',
        };
    }
}
