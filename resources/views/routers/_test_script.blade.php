<script>
function testRouterApi(row) {
    const badge = row.querySelector('.router-status-badge'), users = row.querySelector('.router-users-count');
    badge.className = 'router-status-badge badge bg-secondary';
    badge.innerHTML = '<span class="status-dot"></span>Testing...';
    fetch(row.dataset.testUrl, {method: 'POST', headers: {'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json'}})
        .then(r => r.json())
        .then(d => {
            badge.className = 'router-status-badge badge ' + (d.online ? 'bg-success' : 'bg-danger');
            badge.innerHTML = `<span class="status-dot ${d.online ? 'online' : 'offline'}"></span>${d.online ? 'Online' : 'Offline'}`;
            badge.title = d.message || '';
            if (users) users.textContent = d.online ? `${d.users} user` : '';
        })
        .catch(() => { badge.className = 'router-status-badge badge bg-danger'; badge.innerHTML = '<span class="status-dot offline"></span>Error'; });
}
</script>
