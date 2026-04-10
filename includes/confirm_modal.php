<!-- Custom Confirm Modal -->
<div id="confirmModal" class="modal-overlay z-[100]" onclick="closeConfirm(false)">
    <div class="modal-container max-w-sm p-0 overflow-hidden shadow-2xl ring-1 ring-white/10" onclick="event.stopPropagation()">
        <div class="p-8 bg-slate-900/95 backdrop-blur-xl border border-white/5 rounded-3xl text-center">
            <div class="w-20 h-20 bg-red-500/10 text-red-500 rounded-full flex items-center justify-center mx-auto mb-6 shadow-inner ring-1 ring-red-500/20">
                <i data-lucide="alert-triangle" class="w-10 h-10"></i>
            </div>
            <h3 class="text-xl font-black text-white mb-2 uppercase tracking-tight">Czy na pewno?</h3>
            <p id="confirmMessage" class="text-slate-500 text-sm leading-relaxed mb-8 px-4">Ta operacja jest nieodwracalna i usunie wszystkie dane.</p>
            
            <div class="grid grid-cols-2 gap-4">
                <button onclick="closeConfirm(false)" class="px-6 py-4 bg-slate-800 hover:bg-slate-700 text-slate-300 rounded-2xl font-black text-xs uppercase tracking-widest transition-all border border-white/5 active:scale-95">
                    NIE
                </button>
                <button id="confirmBtn" class="px-6 py-4 bg-red-600 hover:bg-red-500 text-white rounded-2xl font-black text-xs uppercase tracking-widest transition-all shadow-lg shadow-red-600/20 active:scale-95">
                    TAK
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    // Global Confirm logic
    let confirmAction = null;
    function showConfirm(message, action) {
        document.getElementById('confirmMessage').innerText = message;
        confirmAction = action;
        document.getElementById('confirmModal').classList.add('active');
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    function closeConfirm(result) {
        document.getElementById('confirmModal').classList.remove('active');
        if (result && confirmAction) confirmAction();
        confirmAction = null;
    }

    document.getElementById('confirmBtn').onclick = () => closeConfirm(true);

    // Global Esc listener for modals
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            const confirmModal = document.getElementById('confirmModal');
            if (confirmModal && confirmModal.classList.contains('active')) {
                closeConfirm(false);
            }
        }
    });
</script>
