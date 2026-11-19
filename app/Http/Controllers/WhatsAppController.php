<?php

namespace App\Http\Controllers;

use App\Models\PppoeCustomer;
use App\Models\WaConfig;
use App\Models\WaInbox;
use App\Models\WaLog;
use App\Models\WaTemplate;
use App\Services\Settings\SnetSettings;
use App\Services\WhatsApp\DefaultTemplates;
use App\Services\WhatsApp\WaServiceClient;
use App\Services\WhatsApp\WhatsAppGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class WhatsAppController extends Controller
{
    /** Halaman WhatsApp Gateway & Notifikasi (sama dengan S.NET lama). */
    public function index(Request $request, WhatsAppGateway $wa, SnetSettings $settings): View
    {
        Gate::authorize('manage-settings');
        foreach (DefaultTemplates::ALL as $code => $t) {
            WaTemplate::firstOrCreate(['code' => $code], $t + ['is_active' => true]);
        }
        $page = max(1, $request->integer('p', 1));
        $totalLogs = WaLog::count();
        $cfg = $wa->config();

        return view('whatsapp.index', [
            'active_tab' => $request->query('tab', 'config'),
            'wa_config' => ['provider' => $cfg->provider, 'api_url' => $cfg->api_url, 'api_token' => $cfg->api_token ? 'set' : '', 'device_id' => $cfg->device_id, 'is_active' => $cfg->is_active ?? true],
            'templates' => WaTemplate::orderBy('id')->get(['id', 'code', 'name', 'message', 'is_active'])->toArray(),
            'customers' => PppoeCustomer::where('phone', '!=', '')->orderBy('full_name')->get(['id', 'full_name', 'pppoe_username', 'phone', 'monthly_price', 'due_day'])->toArray(),
            'logs' => WaLog::latest('id')->skip(($page - 1) * 20)->take(20)->get()->map(fn ($l) => $l->toArray() + ['created_at' => $l->created_at?->format('Y-m-d H:i:s')])->all(),
            'page_num' => $page,
            'total_logs' => $totalLogs,
            'total_pages' => max(1, (int) ceil($totalLogs / 20)),
            'unread_inbox' => WaInbox::where('is_read', false)->where('is_group', false)->count(),
            'company_name' => $settings->get('pppoe', 'company_name', config('snet.name')),
            'company_phone' => $settings->get('pppoe', 'company_phone', ''),
        ]);
    }

    /** Simpan template dari modal "Edit Template Pesan" (dikenali lewat kode). */
    public function saveTemplate(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $d = $request->validate(['code' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/'], 'name' => ['required', 'string', 'max:100'], 'message' => ['required', 'string', 'max:4000']]);
        WaTemplate::updateOrCreate(['code' => $d['code']], ['name' => $d['name'], 'message' => $d['message'], 'is_active' => true]);

        return redirect()->route('whatsapp.index', ['tab' => 'templates'])->with('status', "Template {$d['name']} disimpan.");
    }

    /** Status/QR/aksi wa-service (dipanggil fetch dari tab Koneksi). */
    public function service(Request $request, WaServiceClient $node): JsonResponse
    {
        Gate::authorize('manage-settings');
        $action = $request->validate(['action' => ['required', 'in:status,qr,logout,restart,reset']])['action'];

        return response()->json(match ($action) {
            'status' => $node->call('GET', '/api/status'),
            'qr' => $node->call('GET', '/api/qr'.($request->boolean('force') ? '?force=1' : ''), [], 25),
            default => $node->call('POST', '/api/'.$action),
        });
    }

    public function updateConfig(Request $request): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $d = $request->validate([
            'provider' => ['required', 'in:waweb,fonnte,ultramsg,greenapi,generic'],
            'api_url' => ['required', 'url', 'max:255'],
            'api_token' => ['nullable', 'string', 'max:255'],
            'device_id' => ['nullable', 'string', 'max:100'],
        ]);
        if (blank($d['api_token'] ?? null)) {
            unset($d['api_token']);
        }
        // Kolom device_id NOT NULL; form mengirim kosong (jadi null) untuk provider tanpa Device ID.
        $d['device_id'] = (string) ($d['device_id'] ?? '');
        $cfg = WaConfig::first() ?? new WaConfig;
        $cfg->fill($d + ['is_active' => $request->boolean('is_active')])->save();

        return redirect()->route('whatsapp.index', ['tab' => 'config'])->with('status', 'Konfigurasi WhatsApp disimpan.');
    }

    public function updateTemplate(Request $request, WaTemplate $template): RedirectResponse
    {
        Gate::authorize('manage-settings');
        $template->update($request->validate(['name' => ['required', 'string', 'max:100'], 'message' => ['required', 'string', 'max:4000']]) + ['is_active' => $request->boolean('is_active')]);

        return redirect()->route('whatsapp.index', ['tab' => 'templates'])->with('status', "Template {$template->name} disimpan.");
    }

    /** Kirim pesan manual (ke nomor atau pelanggan), juga balas inbox. */
    public function send(Request $request, WhatsAppGateway $wa): RedirectResponse
    {
        Gate::authorize('operate');
        $d = $request->validate(['phone' => ['required', 'string', 'max:30'], 'message' => ['required', 'string', 'max:4000'], 'customer_id' => ['nullable', 'exists:pppoe_customers,id']]);
        $customer = isset($d['customer_id']) ? PppoeCustomer::find($d['customer_id']) : null;
        $res = $wa->send($d['phone'], $d['message'], $customer?->id, 'manual', $customer?->full_name ?? '');
        WaInbox::where('sender_phone', WhatsAppGateway::normalizePhone($d['phone']))->where('direction', 'in')->update(['is_read' => true]);

        return back()->with($res['success'] ? 'status' : 'error', $res['message'])->withErrors($res['success'] ? [] : ['message' => $res['message']]);
    }

    public function thread(string $phone): JsonResponse
    {
        Gate::authorize('manage-settings');
        WaInbox::where('sender_phone', $phone)->where('direction', 'in')->update(['is_read' => true]);

        return response()->json(WaInbox::where('sender_phone', $phone)->latest('id')->limit(100)->get(['direction', 'message_text', 'created_at'])->reverse()->values());
    }
}
