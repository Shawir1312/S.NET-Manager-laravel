<?php

namespace App\Services\Hotspot;

use App\Models\HotspotProfile;
use App\Models\Penagihan;
use App\Models\Router;
use App\Models\User;
use App\Models\Voucher;

/**
 * Penagihan reseller voucher: uang setoran dibandingkan voucher yang terpakai sejak penagihan terakhir.
 * Selisih (tekor/lebih) dibawa ke penagihan berikutnya — sama dengan get_reseller_billing_summary() lama.
 */
class ResellerBilling
{
    /** @return array{last: ?Penagihan, new_vouchers: int, carry: int, actual: int} */
    public function summary(Router $router, HotspotProfile $profile, bool $ignorePrevious = false): array
    {
        $last = Penagihan::where('router_id', $router->id)->where('profile_id', $profile->id)->latest('id')->first();
        $carry = match (true) {
            ! $last || $ignorePrevious => 0,
            $last->status_kecocokan === 'tekor' => max(0, $last->voucher_aktual - $last->estimasi_voucher),
            $last->status_kecocokan === 'lebih' => -max(0, $last->estimasi_voucher - $last->voucher_aktual),
            default => 0,
        };
        $new = ($ignorePrevious && ! $last) ? 0 : Voucher::where('profile_id', $profile->id)
            ->where(fn ($q) => $q->where('router_id', $router->id)->orWhereNull('router_id'))
            ->whereIn('status', ['active', 'expired'])
            ->when($last, fn ($q) => $q->whereRaw('COALESCE(used_at, created_at) > ?', [$last->created_at]))
            ->count();

        return ['last' => $last, 'new_vouchers' => $new, 'carry' => $carry, 'actual' => max(0, $new + $carry)];
    }

    public function record(Router $router, HotspotProfile $profile, float $amount, User $by, string $notes = '', bool $ignorePrevious = false): Penagihan
    {
        $price = (float) $profile->price;
        $estimate = $price > 0 ? (int) floor($amount / $price) : 0;
        $actual = $this->summary($router, $profile, $ignorePrevious)['actual'];
        if ($ignorePrevious && $actual === 0) {
            $actual = $estimate;
        }
        $share = $amount * ((float) $profile->reseller_percent / 100);

        return Penagihan::create([
            'router_id' => $router->id, 'profile_id' => $profile->id, 'total_pendapatan' => $amount,
            'bagian_reseller' => $share, 'pendapatan_bersih' => $amount - $share,
            'estimasi_voucher' => $estimate, 'voucher_aktual' => $actual,
            'status_kecocokan' => $estimate === $actual ? 'sesuai' : ($estimate < $actual ? 'tekor' : 'lebih'),
            'catatan' => $notes, 'ditagih_oleh' => $by->id, 'tanggal' => now()->toDateString(),
        ]);
    }
}
