<?php

namespace App\Models;

use App\Audits\Modifiers\VisibilityModifier;
use App\Scopes\OrderNameScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Laravel\Scout\Searchable;
use OwenIt\Auditing\Auditable as AuditableTrait;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Class Tag
 *
 * @package App\Models
 * @property int                    $id
 * @property int                    $user_id
 * @property string                 $name
 * @property int                    $visibility
 * @property Carbon|null            $created_at
 * @property Carbon|null            $updated_at
 * @property string|null            $deleted_at
 * @property-read Collection|Link[] $links
 * @property-read User              $user
 * @method static Builder|Tag byUser(int $user_id = null)
 * @method static Builder|Tag publicOnly()
 * @method static Builder|Tag internalOnly()
 * @method static Builder|Tag privateOnly()
 */
class Tag extends Model implements Auditable
{
    use AuditableTrait;
    use HasFactory;
    use Searchable;
    use ScopesForUser;
    use ScopesVisibility;
    use SoftDeletes;

    public $fillable = [
        'user_id',
        'name',
        'visibility',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'visibility' => 'integer',
    ];

    public static array $allowOrderBy = [
        'id',
        'name',
        'description',
        'visibility',
        'created_at',
        'updated_at',
        'random',
        'links_count',
    ];

    public string $langBase = 'tag';

    protected static function boot()
    {
        parent::boot();

        // Add the OrderNameScope to the Tag model
        static::addGlobalScope(new OrderNameScope());
    }

    /*
     * ========================================================================
     * AUDIT SETTINGS
     */

    public array $auditModifiers = [
        'visibility' => VisibilityModifier::class,
    ];

    /*
     * ========================================================================
     * RELATIONSHIPS
     */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withTrashed();
    }

    public function links(): BelongsToMany
    {
        return $this->belongsToMany(Link::class, 'link_tags', 'tag_id', 'link_id');
    }

    /*
     * ========================================================================
     * NAME SIMILARITY
     */

    /**
     * Determine whether two tag names refer to the same meaning: identical
     * after normalization, a slightly longer version of each other, or at
     * most one character apart for longer names. Short ASCII names only
     * match exactly, otherwise "ai", "ui" and "api" would all be grouped.
     */
    public static function namesSimilar(string $a, string $b): bool
    {
        $a = self::normalizeName($a);
        $b = self::normalizeName($b);

        if ($a === '' || $b === '') {
            return false;
        }

        if ($a === $b) {
            return true;
        }

        $aLength = mb_strlen($a);
        $bLength = mb_strlen($b);
        $shorter = min($aLength, $bLength);

        if ($shorter < 3) {
            return false;
        }

        $hasNonAscii = preg_match('/[^\x00-\x7F]/u', $a . $b) === 1;

        if (mb_strpos($a, $b) !== false || mb_strpos($b, $a) !== false) {
            return abs($aLength - $bLength) <= 2 && ($shorter >= 4 || $hasNonAscii);
        }

        return $shorter >= 6 && self::characterDistance($a, $b) <= 1;
    }

    protected static function normalizeName(string $name): string
    {
        return preg_replace('/\s+/u', '', mb_strtolower(trim($name)));
    }

    /**
     * Levenshtein distance on a per-character basis, so multi-byte names
     * (e.g. Chinese) are measured in characters instead of bytes.
     */
    protected static function characterDistance(string $a, string $b): int
    {
        $a = mb_str_split($a);
        $b = mb_str_split($b);
        $aLength = count($a);
        $bLength = count($b);

        if ($aLength === 0) {
            return $bLength;
        }

        if ($bLength === 0) {
            return $aLength;
        }

        $previous = range(0, $bLength);

        for ($i = 1; $i <= $aLength; $i++) {
            $current = [$i];

            for ($j = 1; $j <= $bLength; $j++) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;

                $current[$j] = min(
                    $previous[$j] + 1,
                    $current[$j - 1] + 1,
                    $previous[$j - 1] + $cost,
                );
            }

            $previous = $current;
        }

        return $previous[$bLength];
    }

    public function searchableAs(): string
    {
        return config('scout.prefix') . 'linkace_tags';
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => (string) $this->id,
            'user_id' => (int) $this->user_id,
            'name' => (string) $this->name,
            'visibility' => (int) $this->visibility,
            'created_at' => optional($this->created_at)->timestamp,
            'updated_at' => optional($this->updated_at)->timestamp,
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return in_array(
            config('linkace.search.driver'),
            config('linkace.search.external_drivers', []),
            true
        );
    }
}
