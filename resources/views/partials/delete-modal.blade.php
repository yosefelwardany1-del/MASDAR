<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body text-center pt-4">
                <i class="bi bi-trash3 text-danger fs-2 d-block mb-2"></i>
                <h5 class="fw-bold">{{ __('Confirm delete') }}</h5>
                <p class="text-muted mb-0" id="deleteMessage">{{ __('Are you sure? This cannot be undone.') }}</p>
            </div>
            <div class="modal-footer border-0 justify-content-center gap-2">
                <button type="button" class="btn btn-light px-4" data-bs-dismiss="modal">{{ __('Cancel') }}</button>
                <form id="deleteForm" method="POST">
                    @csrf @method('DELETE')
                    <button type="submit" class="btn btn-danger px-4">{{ __('Delete') }}</button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
function confirmDelete(url, message) {
    document.getElementById('deleteForm').action = url;
    if (message) document.getElementById('deleteMessage').textContent = message;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}
</script>
