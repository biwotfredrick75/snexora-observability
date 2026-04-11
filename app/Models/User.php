<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, HasRoles;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'real_name',
        'password',
        'password_last_changed',
        'password_expiry_notified',
        'phone',
        'email',
        'pin',
        'role_id',
        'language',
        'date_format',
        'date_sep',
        'tho_sep',
        'dec_sep',
        'theme',
        'page_size',
        'prices_dec',
        'qty_dec',
        'rates_dec',
        'percent_dec',
        'show_gl',
        'show_codes',
        'show_hints',
        'graphic_links',
        'rep_popup',
        'sticky_doc_date',
        'print_profile',
        'def_print_destination',
        'def_print_orientation',
        'pos',
        'startup_tab',
        'transaction_days',
        'save_report_selections',
        'use_date_picker',
        'default_store',
        'route_id',
        'query_size',
        'inactive',
        'last_visit_date',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'pin',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'password_last_changed' => 'datetime',
            'password_expiry_notified' => 'boolean',
            'date_format' => 'integer',
            'date_sep' => 'integer',
            'tho_sep' => 'integer',
            'dec_sep' => 'integer',
            'prices_dec' => 'integer',
            'qty_dec' => 'integer',
            'rates_dec' => 'integer',
            'percent_dec' => 'integer',
            'show_gl' => 'boolean',
            'show_codes' => 'boolean',
            'show_hints' => 'boolean',
            'graphic_links' => 'boolean',
            'rep_popup' => 'boolean',
            'sticky_doc_date' => 'boolean',
            'def_print_destination' => 'integer',
            'def_print_orientation' => 'integer',
            'pos' => 'integer',
            'transaction_days' => 'integer',
            'save_report_selections' => 'integer',
            'use_date_picker' => 'boolean',
            'query_size' => 'integer',
            'inactive' => 'boolean',
            'last_visit_date' => 'datetime',
        ];
    }

    /**
     * Boot method for User model.
     */
    protected static function booted(): void
    {
        static::creating(function (User $user) {
            // Set defaults for new users
            if (!$user->role_id) {
                $user->role_id = 1; // Default role
            }
            if (is_null($user->inactive)) {
                $user->inactive = 0; // Active by default
            }
            if (is_null($user->password_expiry_notified)) {
                $user->password_expiry_notified = 0;
            }
        });
    }
}
