<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">
@switch($icon ?? 'grid')
@case('home')<path d="m3 10 9-7 9 7v11h-6v-7H9v7H3z"/>@break
@case('arrow-left')<path d="M20 12H4m7-7-7 7 7 7"/>@break
@case('truck')<path d="M2 5h13v12H2zM15 10h4l3 4v3h-7"/><circle cx="6" cy="18" r="2"/><circle cx="18" cy="18" r="2"/>@break
@case('user')<circle cx="12" cy="8" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>@break
@case('clock')<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>@break
@case('money')<rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="12" cy="12" r="3"/><path d="M6 12h.1M18 12h.1"/>@break
@case('chart')<path d="M4 20V10h4v10M10 20V4h4v16M16 20V8h4v12"/>@break
@case('support')<path d="M4 13v-2a8 8 0 0 1 16 0v2M4 11H2v7h4v-7zM20 11h2v7h-4v-7zM20 18v3h-6"/>@break
@case('settings')<path d="M4 6h16M4 12h16M4 18h16"/><circle cx="9" cy="6" r="2"/><circle cx="16" cy="12" r="2"/><circle cx="10" cy="18" r="2"/>@break
@default<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>
@endswitch</svg>
