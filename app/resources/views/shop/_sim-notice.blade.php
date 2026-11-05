{{-- 每個商店、結帳與訂單畫面都必須出現的模擬標示（COM-11）。 --}}
<div class="notice notice--warning sim-notice" role="note" data-sim-notice>
    <p class="notice__title"><span class="badge badge--sim">模擬</span> 模擬操作，不收款、不出貨</p>
    <p class="sim-notice__body">這是模擬商店：不會向您收取任何費用、不會寄送商品，也不收集信用卡、真實地址或電話。訂單資料預設 {{ (int) config('mysite.retention.simulation_days', 30) }} 天後匿名化。</p>
</div>
