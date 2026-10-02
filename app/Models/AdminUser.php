<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Notifiable;

/**
 * A pre-authorized COMELEC IT administrator record.
 *
 * Provisioned outside the application (comelec:provision-admins) with an
 * `authorized_email`; `google_subject` stays NULL until the first verified Google
 * login binds it. There is no UI or mass-assignable path that can create, promote,
 * demote or delete an admin: `authorized_email`, `google_subject` and `role` are
 * not fillable.
 */
class AdminUser extends Model implements AuthenticatableContract
{
    use Authenticatable, HasFactory, Notifiable;

    public const ROLE = 'BUKSU_COMELEC_IT_ADMIN';

    protected $table = 'admin_users';

    /** Only presentation data is fillable; identity and role never are. */
    protected $fillable = ['display_name'];

    protected $hidden = ['google_subject'];

    /** No password / remember-me: Google OAuth is the only credential. */
    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
