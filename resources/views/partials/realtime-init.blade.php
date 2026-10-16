{{--
    Inisialisasi Realtime untuk halaman kasir dan admin (Ticket 09).

    Menyuntikkan konfigurasi channel dan fungsi `KasirRealtime.subscribe()`.
    Sengaja dipisah dari logika halaman supaya setiap halaman hanya perlu
    memanggil satu baris, dan ada tepat satu tempat yang bertanggung jawab atas
    langganan WebSocket.

    @var array<string, mixed> $realtime
--}}

{{-- CDN Supabase JS. Versi dijaga agar tidak ikut berubah sendiri. --}}
<script src="https://cdn.jsdelivr.net/npm/@supabase/supabase-js@2.45.4/dist/umd/supabase.min.js"></script>
<script>
    window.KasirRealtime = (() => {
        const config = @json($realtime);

        let client = null;
        const channels = new Map();

        /**
         * Klien Supabase dibuat malas. Halaman yang tidak memakai Realtime
         * (login, laporan, audit log) tidak perlu paysCDN-nya diunduh.
         */
        function getClient() {
            if (client) return client;

            if (!config.enabled) {
                console.warn('[realtime] belum dikonfigurasi; pembaruan langsung dimatikan.');
                return null;
            }

            client = window.supabase.createClient(config.url, config.key);
            return client;
        }

        /**
         * Berlangganan sebuah channel.
         *
         * `handlers` diisi nama event -> fungsi. Event yang tidak terdaftar
         * diabaikan, jadi menambah event baru di server tidak menyebabkan
         * galat di halaman lama yang belum mengenal event barunya.
         */
        function subscribe(topic, handlers) {
            if (!topic) {
                console.warn('[realtime] topic kosong, langganan dilewati.');
                return null;
            }

            const supabase = getClient();

            if (!supabase) return null;

            if (channels.has(topic)) {
                return channels.get(topic);
            }

            const channel = supabase.channel(topic, { config: { broadcast: { self: false } } });

            Object.entries(handlers).forEach(([event, handler]) => {
                channel.on('broadcast', { event }, (message) => {
                    handler(message.payload ?? {});
                });
            });

            channel.subscribe((status) => {
                if (status === 'SUBSCRIBED') {
                    console.info('[realtime] berlangganan', topic);
                } else if (status === 'CHANNEL_ERROR' || status === 'TIMED_OUT') {
                    // Realtime adalah tambahan tampilan. Halaman harus tetap
                    // berfungsi saat koneksi ini putus, jadi kegagalan tidak
                    // dibawa ke mana-mana; Supabase otomatis mencoba lagi.
                    console.warn('[realtime] gagal berlangganan', topic, status);
                }
            });

            channels.set(topic, channel);
            return channel;
        }

        return { config, subscribe };
    })();
</script>