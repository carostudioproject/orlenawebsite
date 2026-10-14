<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ClosedDate;
use App\Models\Order;
use App\Models\Setting;
use App\Support\Audit;
use App\Support\PreorderDate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/** PO schedule: cutoff and payment close, closed weekdays and dates, delivery tolerance. Quotas are set per outlet. */
class ScheduleController extends Controller
{
    public function edit(PreorderDate $dates)
    {
        $today = now('Asia/Makassar')->toDateString();
        // Upcoming load per requested date, so staff see which days are near capacity.
        $load = Order::where('order_status', '!=', 'cancelled')->whereDate('requested_date', '>=', $today)
            ->selectRaw('requested_date, COUNT(*) as total')->groupBy('requested_date')->orderBy('requested_date')->limit(14)->get()
            ->map(fn ($row) => ['date' => $row->requested_date->toDateString(), 'total' => (int) $row->total]);

        return Inertia::render('Admin/Schedule', [
            'settings' => ['cutoff_time' => $dates->cutoffTime(), 'closed_weekdays' => $dates->closedWeekdays(), 'delivery_tolerance' => $dates->deliveryTolerance(), 'delivery_min_order' => $dates->deliveryMinOrder(), 'delivery_radius' => $dates->deliveryRadius(),
                'payment_close_time' => $dates->paymentCloseTime(), 'max_days' => $dates->maxDays(), 'ck_whatsapp' => Setting::get('ck.whatsapp')],
            'weekdays' => collect(PreorderDate::WEEKDAYS_EN)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'closedDates' => ClosedDate::whereDate('date', '>=', $today)->orderBy('date')->get(['id', 'date', 'reason']),
            'earliest' => $dates->earliest(), 'load' => $load,
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'cutoff_time' => ['required', 'date_format:H:i'],
            'payment_close_time' => ['required', 'date_format:H:i', 'after_or_equal:cutoff_time'],
            'max_days' => ['required', 'integer', 'min:1', 'max:365'],
            'closed_weekdays' => ['present', 'array', 'max:6'],
            'closed_weekdays.*' => ['integer', 'distinct', Rule::in(array_keys(PreorderDate::WEEKDAYS))],
            'delivery_tolerance' => ['required', 'integer', 'min:0', 'max:600'],
            'delivery_min_order' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'delivery_radius' => ['nullable', 'integer', 'min:1', 'max:200'],
            // Where "Send via WhatsApp" on Rekap CK goes (international format, e.g. 6281234567890).
            'ck_whatsapp' => ['nullable', 'regex:/^62[0-9]{8,13}$/'],
        ], ['payment_close_time.after_or_equal' => 'Payment close must be at or after the order deadline (both on D-1).', 'ck_whatsapp.regex' => 'Use the international format without + or spaces, e.g. 6281234567890.', 'closed_weekdays.max' => 'At least one day a week must stay open for PO.', 'cutoff_time.date_format' => 'The order deadline must be in HH:MM format, e.g. 18:00.']);
        $values = ['po.cutoff_time' => $data['cutoff_time'], 'po.closed_weekdays' => array_map('intval', $data['closed_weekdays']), 'delivery.tolerance_minutes' => (int) $data['delivery_tolerance'],
            'delivery.min_order' => $data['delivery_min_order'] ?? null, 'delivery.max_radius_km' => $data['delivery_radius'] ?? null,
            'po.payment_close_time' => $data['payment_close_time'], 'po.max_days' => (int) $data['max_days'],
            'ck.whatsapp' => $data['ck_whatsapp'] ?? null];
        Setting::put($values);
        Audit::log('settings.schedule_updated', 'settings', 0, $values);

        return back()->with('success', 'PO schedule saved.');
    }

    public function storeClosedDate(Request $request)
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now('Asia/Makassar')->toDateString(), Rule::unique('closed_dates', 'date')],
            'reason' => ['nullable', 'string', 'max:160'],
        ], ['date.unique' => 'This date is already closed.', 'date.after_or_equal' => 'Choose today or a later date.']);
        $closed = ClosedDate::create($data);
        Audit::record('settings.date_closed', $closed, $data);
        $orders = Order::whereDate('requested_date', $data['date'])->where('order_status', '!=', 'cancelled')->count();

        return back()->with('success', $data['date'].' is closed for new PO.'.($orders ? ' There are '.$orders.' order(s) on this date; contact the customers if they need rescheduling.' : ''));
    }

    public function destroyClosedDate(ClosedDate $closedDate)
    {
        $closedDate->delete();
        Audit::record('settings.date_reopened', $closedDate, ['date' => $closedDate->date->toDateString()]);

        return back()->with('success', $closedDate->date->toDateString().' is open again.');
    }
}
