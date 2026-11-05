【模擬通知】此信只寫入郵件捕捉器（log），不會寄給任何人。

模擬訂單 {{ $order->displayNumber() }} 已建立。
狀態：{{ $order->status->label() }}
件數：{{ $order->item_count }}
總計：{{ \App\Domain\Commerce\MoneyFormatter::format($order->total) }}（模擬，不收款、不出貨）

@foreach ($order->items as $item)
- {{ $item->product_title }}／{{ $item->variant_name }}（{{ $item->sku }}）× {{ $item->quantity }}＝{{ \App\Domain\Commerce\MoneyFormatter::format($item->line_total) }}
@endforeach
