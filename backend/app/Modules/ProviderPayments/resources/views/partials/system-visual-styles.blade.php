<style>
/* Shared compact layout for Pago a Proveedores screens. */
.content{padding:20px clamp(16px,2vw,32px) 40px}
.content .page-heading{margin-bottom:7px}
.content .intro{margin:4px 0 14px;font-size:14px;line-height:1.4}
.content h2{font-size:18px;line-height:1.3}
.content h3{font-size:15px;line-height:1.3}
.content :is(.card,.box,details.review-group){min-width:0;border-radius:10px}
.content .card{padding:12px 14px}
.content :is(.card,.box) h2:first-child{margin-top:0}
.content :is(.button,button){min-height:32px;padding:6px 10px;font-size:12px}
.content :is(input:not([type=checkbox]):not([type=radio]):not([type=hidden]),select,textarea){font-size:12px}
.content :is(input:not([type=checkbox]):not([type=radio]):not([type=hidden]),select){min-height:32px}
.content :is(th,td){padding:7px 9px}
.content .master-tools{gap:8px;margin-bottom:14px;padding:11px 12px}
.content .master-tools label{gap:3px;font-size:11px}
.content .master-tools :is(input,select){padding:6px 8px}
.content :is(.worked-filters,.sheet-filters,.real-filters){grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px;margin:10px 0;padding:12px}
.content :is(.worked-filters,.sheet-filters,.real-filters) label{gap:3px;font-size:11px}
.content :is(.worked-filters,.sheet-filters,.real-filters) :is(input,select){width:100%;min-width:0;padding:5px 7px}
.content :is(.worked-filter-actions,.sheet-actions,.filter-actions){display:flex;align-items:end;gap:6px;flex-wrap:wrap}
.content :is(.agreements-metrics,.services-summary,.cv-stats,.special-summary){display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,175px),1fr));gap:8px;margin:10px 0 14px}
.content :is(.agreements-metrics,.services-summary,.cv-stats,.special-summary) .card{min-width:0;min-height:48px;padding:8px 10px}
.content :is(.agreements-metrics,.services-summary,.cv-stats,.special-summary) strong{font-size:17px}
.content .services-summary{grid-template-columns:repeat(3,minmax(0,1fr))}
.content .services-toolbar:not(.services-upload){display:grid;grid-template-columns:minmax(105px,.7fr) minmax(105px,.7fr) minmax(150px,1.2fr) minmax(150px,1.2fr) minmax(140px,1fr) auto auto;align-items:end}
.content .services-toolbar:not(.services-upload) label{min-width:0}
.content .services-toolbar:not(.services-upload) :is(input,select){width:100%;min-width:0}
.content .services-toolbar:not(.services-upload) :is(button,.button){white-space:nowrap}
.content :is(.metrics,.client-dashboard,.bank-dashboard,.provider-dashboard,.result-grid){gap:8px;margin:10px 0 14px}
.content :is(.metrics .metric,.client-metric,.bank-metric,.provider-metric,.result-grid .metric){padding:12px 14px}
.content :is(.metrics .metric,.client-metric,.bank-metric,.provider-metric,.result-grid .metric) strong{margin-top:3px;font-size:23px}
.content :is(.summary-grid,.payment-overview-grid){gap:10px}
.content .compile-list,.content .upload-wrap{max-width:none}
.content .compile-toolbar{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));align-items:start;gap:7px}
.content .compile-toolbar>:is(details,form,.button){min-width:0;width:100%}
.content .compile-toolbar>.button{margin-left:0}
.content .compile-toolbar .compile-menu>summary,.content .compile-toolbar>.button,.content .compile-toolbar>form>button{display:flex;align-items:center;justify-content:center;width:100%;min-height:44px;padding:6px 9px;text-align:center;white-space:normal;font-size:12px;line-height:1.25}
.content :is(.filter,.compile-period,.review-tools,.rate-filter,.special-tools,.cv-tools,.services-toolbar){gap:8px;margin-top:10px;margin-bottom:12px}
.content .filter{padding:10px 12px}
.content :is(.record,.group,.review-group){margin-top:8px;margin-bottom:8px}
.content .record summary{padding:10px 12px}
.content .agreements-float .agreements-float-toggle{min-height:24px;padding:1px 5px;font-size:15px}
@media(min-width:1600px){
    .content .compile-toolbar{grid-template-columns:repeat(7,minmax(0,1fr))}
}
@media(max-width:760px){
    .content{padding:18px 14px 32px}
    .content .services-summary{grid-template-columns:repeat(2,minmax(0,1fr))}
    .content .services-toolbar:not(.services-upload){grid-template-columns:repeat(2,minmax(0,1fr))}
    .content .compile-toolbar{grid-template-columns:repeat(2,minmax(0,1fr))}
    .content :is(.worked-filters,.sheet-filters,.real-filters){grid-template-columns:repeat(auto-fit,minmax(min(100%,150px),1fr))}
    .content :is(.client-dashboard,.bank-dashboard,.provider-dashboard,.metrics,.result-grid){grid-template-columns:repeat(auto-fit,minmax(min(100%,170px),1fr))}
}
@media(max-width:1100px) and (min-width:761px){
    .content .services-toolbar:not(.services-upload){grid-template-columns:repeat(3,minmax(0,1fr))}
    .content .compile-toolbar{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media(max-width:480px){
    .content .services-summary,.content .services-toolbar:not(.services-upload),.content .compile-toolbar{grid-template-columns:1fr}
}
</style>
