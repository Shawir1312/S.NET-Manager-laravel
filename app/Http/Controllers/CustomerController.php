<?php

namespace App\Http\Controllers;

use App\Enums\ServiceType;
use App\Models\ConfigurationJob;
use App\Models\ConfigurationTemplate;
use App\Models\OntDevice;
use App\Models\PppoeCustomer;
use App\Models\PppoePayment;
use App\Models\Router;
use App\Models\WaTemplate;
use App\Services\Mikrotik\MikrotikConnection;
use App\Services\Mikrotik\MikrotikConnector;
use App\Services\Mikrotik\MikrotikException;
use App\Services\Pppoe\CustomerOntProvisioner;
use App\Services\Pppoe\CustomerService;
use App\Services\Pppoe\OntWifiService;
use App\Services\Provisioning\ProvisioningException;
use App\Services\Provisioning\ProvisioningService;
use App\Services\Radius\RadiusSync;
use App\Services\Settings\SnetSettings;
use App\Services\WhatsApp\DefaultTemplates;
use App\Services\WhatsApp\WhatsAppGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Throwable;

class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $customers) {}

    /** Pelanggan Rumahan (tampilan S.NET): status online, ONT, pemakaian bulan terpilih & status bayar. */
    public function index(Request $request): View
    {
        Gate::authorize('operate');
        $q = trim((string) $request->query('q'));
        $month = CarbonImmutable::createFromFormat('Y-m', (string) $request->query('month', now()->format('Y-m'))) ?: CarbonImmutable::now();
        $month = $month->startOfMonth();
        $routerId = $request->integer('router_id');
        $status = (string) $request->query('status');

        // Sesi PPPoE aktif dari radacct (tanpa harus menghubungi MikroTik satu per satu).
        $sessions = DB::table('radacct')->whereNull('acctstoptime')->orderByDesc('radacctid')
            ->get(['username', 'framedipaddress', 'acctstarttime'])->keyBy('username');
        $onlineSince = now()->subSeconds((int) config('ontconfig.online_threshold_seconds'));
        $ontOnline = OntDevice::where('last_inform_at', '>=', $onlineSince)->pluck('last_inform_at', 'serial_number');

        $customers = PppoeCustomer::with('router:id,name')
            ->when($routerId > 0, fn ($b) => $b->where('router_id', $routerId))
            ->when(in_array($status, ['active', 'isolated'], true), fn ($b) => $b->where('status', $status))
            ->when($status === 'online', fn ($b) => $b->whereIn('pppoe_username', $sessions->keys()))
            ->when($status === 'offline', fn ($b) => $b->whereNotIn('pppoe_username', $sessions->keys()))
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('full_name', 'like', "%{$q}%")
                ->orWhere('pppoe_username', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")->orWhere('ont_sn', 'like', "%{$q}%")))
            ->orderBy('full_name')->get();

        $ids = $customers->pluck('id');
        $payments = PppoePayment::whereIn('customer_id', $ids)->where('period_month', $month->month)->where('period_year', $month->year)
            ->get(['customer_id', 'amount', 'midtrans_status', 'payment_method'])->groupBy('customer_id');
        $usage = DB::table('radacct')->whereIn('username', $customers->pluck('pppoe_username'))
            ->whereBetween('acctstarttime', [$month, $month->endOfMonth()])
            ->selectRaw('username, count(*) as sessions, coalesce(sum(acctsessiontime), 0) as secs, coalesce(sum(acctoutputoctets), 0) as dl, coalesce(sum(acctinputoctets), 0) as ul')
            ->groupBy('username')->get()->keyBy('username');

        $today = (int) now()->day;
        $rows = $customers->map(function (PppoeCustomer $c) use ($payments, $usage, $sessions, $ontOnline, $today, $month) {
            $p = $payments[$c->id] ?? collect();
            $paid = $p->filter(fn ($x) => $x->midtrans_status === 'paid' || ($x->midtrans_status === null && $x->payment_method === 'cash'))->sum('amount');
            $u = $usage[$c->pppoe_username] ?? null;

            return (object) [
                'c' => $c,
                'session' => $sessions[$c->pppoe_username] ?? null,
                'ontOnline' => $c->ont_sn !== '' ? $ontOnline[$c->ont_sn] ?? null : null,
                'paid' => $paid,
                'pending' => $p->where('midtrans_status', 'pending')->count(),
                'late' => $c->status === 'active' && ! $paid && $month->isSameMonth(now()) && $c->due_day <= $today,
                'usage' => $u ? ['dl' => (int) $u->dl, 'ul' => (int) $u->ul, 'total' => (int) $u->dl + (int) $u->ul, 'secs' => (int) $u->secs, 'sessions' => (int) $u->sessions] : null,
            ];
        });

        return view('customers.index', [
            'rows' => $rows,
            'routers' => Router::orderBy('name')->get(['id', 'name']),
            'selRouter' => $routerId ? Router::find($routerId) : null,
            'month' => $month,
            'monthOptions' => collect(range(0, 11))->mapWithKeys(fn ($i) => [now()->startOfMonth()->subMonths($i)->format('Y-m') => now()->startOfMonth()->subMonths($i)->translatedFormat('F Y')]),
            'stats' => [
                'total' => $rows->count(),
                'mrr' => $rows->where('c.is_free', false)->sum('c.monthly_price'),
                'lunas' => $rows->where('paid', '>', 0)->count(),
                'lunasNominal' => $rows->sum('paid'),
                'nunggak' => $rows->where('late', true)->count(),
                'nunggakNominal' => $rows->where('late', true)->sum('c.monthly_price'),
                'online' => $rows->filter(fn ($r) => $r->session)->count(),
                'isolir' => $rows->where('c.status', 'isolated')->count(),
                'ontOnline' => $rows->filter(fn ($r) => $r->ontOnline)->count(),
                'dl' => $rows->sum(fn ($r) => $r->usage['dl'] ?? 0),
                'ul' => $rows->sum(fn ($r) => $r->usage['ul'] ?? 0),
            ],
            'waTemplates' => WaTemplate::where('is_active', true)->get(['code', 'name', 'message'])->whenEmpty(fn () => collect(DefaultTemplates::ALL)->map(fn ($t, $code) => (object) ($t + ['code' => $code]))->values()),
            'settings' => app(SnetSettings::class)->all('pppoe'),
        ]);
    }

    /** Modal "Ganti Wi-Fi" di daftar pelanggan: simpan & (opsional) kirim ke ONT. */
    public function quickWifi(Request $request, PppoeCustomer $customer, OntWifiService $wifi): RedirectResponse
    {
        Gate::authorize('operate');
        $d = $request->validate(['wifi_ssid' => ['nullable', 'string', 'min:2', 'max:32'], 'wifi_pass' => ['required', 'string', 'min:8', 'max:63']], [], ['wifi_ssid' => 'nama Wi-Fi', 'wifi_pass' => 'password Wi-Fi']);
        $ssid = filled($d['wifi_ssid'] ?? null) ? $d['wifi_ssid'] : null;

        if (! $request->boolean('push_ont') || $customer->ont_sn === '') {
            $customer->forceFill(array_filter(['ont_wifi_ssid' => $ssid, 'ont_wifi_pass' => $d['wifi_pass']]))->save();

            return back()->with('status', "Data Wi-Fi {$customer->full_name} disimpan (tidak dikirim ke ONT).");
        }
        try {
            $msg = $wifi->change($customer, $ssid, $d['wifi_pass']);
        } catch (Throwable $e) {
            return back()->withErrors(['wifi_pass' => 'Gagal push Wi-Fi ke ONT: '.$e->getMessage()]);
        }

        return back()->with('status', "{$customer->full_name}: {$msg}");
    }

    /** Modal "Atur Akun Portal": username/password portal + kirim info login via WA. */
    public function quickPortal(Request $request, PppoeCustomer $customer, WhatsAppGateway $wa): RedirectResponse
    {
        Gate::authorize('operate');
        $d = $request->validate([
            'portal_username' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9@._\-]+$/', Rule::unique('pppoe_customers')->ignore($customer->id)],
            'portal_password' => ['nullable', 'string', 'min:4', 'max:64'],
        ]);
        $customer->portal_username = $d['portal_username'];
        if (filled($d['portal_password'] ?? null)) {
            $customer->portal_password = $d['portal_password'];
        }
        $customer->save();

        $note = '';
        if ($request->boolean('send_wa') && $customer->phone !== '' && filled($d['portal_password'] ?? null)) {
            $res = $wa->sendTemplate('welcome_customer', $customer->phone, $customer->toArray() + ['portal_username' => $customer->portal_username, 'portal_password' => $d['portal_password']], $customer->id);
            $note = $res && $res['success'] ? ' Info login dikirim via WhatsApp.' : ' WhatsApp gagal dikirim.';
        }

        return back()->with('status', "Akun portal {$customer->full_name} disimpan.{$note}");
    }

    public function create(Request $request): View
    {
        Gate::authorize('operate');
        $router = Router::find($request->integer('router_id')) ?? Router::orderBy('name')->first();

        return view('customers.form', [
            'customer' => new PppoeCustomer(['router_id' => $router?->id, 'due_day' => 1, 'status' => 'active', 'ont_vlan' => null]),
            'suggestedPassword' => CustomerService::randomPassword(),
        ] + $this->formOptions($router));
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('operate');
        $data = $this->validated($request);

        try {
            $r = $this->customers->create($data, $request->user(), $this->options($request) + ['send_welcome' => $request->boolean('send_welcome')]);
        } catch (MikrotikException $e) {
            return back()->withInput()->withErrors(['router_id' => $e->getMessage()]);
        }

        // Dari aplikasi lapangan: kembali ke beranda lapangan, bukan halaman admin.
        return ($request->boolean('from_field') ? redirect()->route('field.home') : redirect()->route('customers.show', $r['customer']))
            ->with('status', implode(' ', $r['notes']));
    }

    public function show(PppoeCustomer $customer): View
    {
        Gate::authorize('operate');
        $customer->load(['router.genieAcsSetting', 'payments' => fn ($q) => $q->latest('id')->limit(12)]);
        $device = $customer->ontDevice();

        return view('customers.show', [
            'customer' => $customer,
            'device' => $device,
            'jobs' => $device ? ConfigurationJob::where('ont_device_id', $device->id)->with('user:id,name', 'template:id,name')->latest('id')->limit(10)->get() : collect(),
            'templates' => ConfigurationTemplate::orderBy('name')->get(['id', 'name', 'service_type', 'is_default', 'payload']),
        ]);
    }

    public function edit(PppoeCustomer $customer): View
    {
        Gate::authorize('operate');

        return view('customers.form', ['customer' => $customer, 'suggestedPassword' => null] + $this->formOptions($customer->router));
    }

    public function update(Request $request, PppoeCustomer $customer): RedirectResponse
    {
        Gate::authorize('operate');
        $data = $this->validated($request, $customer);

        try {
            $r = $this->customers->update($customer, $data, $request->user(), $this->options($request));
        } catch (MikrotikException $e) {
            return back()->withInput()->withErrors(['router_id' => $e->getMessage()]);
        }

        return redirect()->route('customers.show', $customer)->with('status', implode(' ', $r['notes']));
    }

    public function destroy(PppoeCustomer $customer): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $this->customers->delete($customer);

        return redirect()->route('customers.index')->with('status', "Pelanggan {$customer->full_name} dihapus.");
    }

    /** Impor/sinkron PPP secret dari MikroTik ke daftar pelanggan. */
    public function syncFromMikrotik(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $router = Router::findOrFail($request->validate(['router_id' => ['required', 'exists:routers,id']])['router_id']);
        try {
            $r = $this->customers->syncFromMikrotik($router, $request->user());
        } catch (MikrotikException $e) {
            return back()->withErrors(['router_id' => $e->getMessage()]);
        }

        return back()->with('status', "Sync {$router->name}: {$r['inserted']} pelanggan baru, {$r['updated']} diperbarui.");
    }

    /** Isolir / buka isolir / suspend cepat dari halaman pelanggan. */
    public function status(Request $request, PppoeCustomer $customer): RedirectResponse
    {
        Gate::authorize('operate');
        $status = $request->validate(['status' => ['required', 'in:active,isolated,suspended']])['status'];
        $ok = $this->customers->setStatus($customer, $status, $status === 'isolated' ? 'Manual oleh '.$request->user()->name : '');

        return back()->with('status', 'Status diubah ke '.$status.'.'.($ok ? '' : ' MikroTik tidak bisa dihubungi; RADIUS sudah diperbarui, sinkron ulang nanti.'));
    }

    /** Tab Config ONT: push cepat PPPoE (+ Hotspot bila ada di template PPPoE) atau Hotspot saja. */
    public function pushOnt(Request $request, PppoeCustomer $customer, CustomerOntProvisioner $ont, RadiusSync $radius, ProvisioningService $provisioning): RedirectResponse
    {
        Gate::authorize('configure-ont');
        $d = $request->validate([
            'service' => ['required', 'in:pppoe,bridge'],
            'pppoe_template_id' => ['nullable', Rule::exists('configuration_templates', 'id')->where('service_type', 'pppoe')],
            'hotspot_template_id' => ['nullable', Rule::exists('configuration_templates', 'id')->where('service_type', 'bridge')],
        ]);
        if ($customer->ont_sn === '') {
            return back()->withErrors(['ont' => 'SN ONT pelanggan belum diisi.']);
        }

        $warnings = [];
        try {
            if ($d['service'] === 'bridge') {
                $device = $ont->findDevice($customer);
                $template = $ont->templateFor($customer, ServiceType::Bridge, $d['hotspot_template_id'] ?? null);
                $provisioning->start($device, $ont->buildInput($customer, $template, null), $request->user(), $template->id);
            } else {
                $warnings = $ont->push($customer, $request->user(), $radius->passwordOf($customer->pppoe_username), $d['pppoe_template_id'] ?? null)['warnings'];
            }
        } catch (ProvisioningException $e) {
            return back()->withErrors(['ont' => $e->getMessage()]);
        }

        return back()->with('tab', 'ont')->with('status', trim('Konfigurasi ONT diantrekan. Status diperbarui otomatis di bawah. '.implode(' ', $warnings)));
    }

    /** @return array{push_ont: bool, pppoe_template_id: ?int} */
    private function options(Request $request): array
    {
        return [
            'push_ont' => $request->boolean('push_ont') && $request->user()->can('configure-ont'),
            'pppoe_template_id' => $request->integer('pppoe_template_id') ?: null,
        ];
    }

    /** @return array<string, mixed> */
    private function formOptions(?Router $router): array
    {
        $profiles = PppoeCustomer::where('profile', '!=', '')->distinct()->orderBy('profile')->pluck('profile')->all();
        if ($router) {
            $live = rescue(fn () => app(MikrotikConnector::class)->with($router, fn (MikrotikConnection $api) => $api->pppProfiles()), [], report: false);
            $profiles = array_values(array_unique([...$live, ...$profiles]));
        }

        return [
            'routers' => Router::orderBy('name')->get(['id', 'name']),
            'profiles' => $profiles,
            'templates' => ConfigurationTemplate::orderBy('name')->get(['id', 'name', 'service_type', 'is_default', 'payload']),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?PppoeCustomer $customer = null): array
    {
        $request->merge(['ont_sn' => strtoupper(trim((string) $request->input('ont_sn')))]);

        return $request->validate([
            'router_id' => ['required', 'exists:routers,id'],
            'full_name' => ['required', 'string', 'max:150'],
            'phone' => ['nullable', 'string', 'max:25', 'regex:/^[0-9+\- ]*$/'],
            'address' => ['nullable', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'pppoe_username' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9@._\-]+$/',
                Rule::unique('pppoe_customers')->where('router_id', $request->integer('router_id'))->ignore($customer?->id)],
            'pppoe_password' => ['nullable', 'string', 'min:4', 'max:64'],
            'profile' => ['required', 'string', 'max:100'],
            'monthly_price' => ['required_unless:is_free,1', 'nullable', 'integer', 'min:0'],
            'is_free' => ['sometimes', 'boolean'],
            'due_day' => ['required', 'integer', 'between:1,28'],
            'status' => ['required', 'in:active,isolated,suspended'],
            'portal_username' => ['nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9@._\-]+$/', Rule::unique('pppoe_customers')->ignore($customer?->id)],
            'portal_password' => ['nullable', 'string', 'min:6', 'max:64'],
            'ont_sn' => ['nullable', 'string', 'max:64', 'regex:/^[A-Z0-9\-_.]*$/'],
            'ont_vlan' => ['nullable', 'integer', 'between:1,4094'],
            'ont_wifi_ssid' => ['nullable', 'string', 'max:32'],
            'ont_wifi_pass' => ['nullable', 'string', 'min:8', 'max:63'],
        ], [], ['ont_wifi_pass' => 'password WiFi', 'ont_sn' => 'SN ONT']);
    }
}
