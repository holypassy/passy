<script>
    const API_BASE = '/api';
    
    // Logout function
    document.getElementById('logoutBtn')?.addEventListener('click', async () => {
        try {
            const response = await fetch(`${API_BASE}/auth.php?action=logout`, {
                method: 'POST'
            });
            const data = await response.json();
            if (data.success) {
                window.location.href = '/index.php';
            }
        } catch (error) {
            console.error('Logout error:', error);
            window.location.href = '/index.php';
        }
    });
    
    // Check authentication
    async function checkAuth() {
        try {
            const response = await fetch(`${API_BASE}/auth.php?action=check`);
            const data = await response.json();
            if (!data.authenticated) {
                window.location.href = '/index.php';
            }
            return data;
        } catch (error) {
            console.error('Auth check error:', error);
            window.location.href = '/index.php';
        }
    }
    
    // Format money
    function formatMoney(amount) {
        return 'UGX ' + new Intl.NumberFormat().format(amount);
    }
    
    // Format date
    function formatDate(date) {
        if (!date) return '';
        return new Date(date).toLocaleDateString('en-US', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }
    
    // Show toast notification
    function showToast(message, type = 'success') {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `
            <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
            <span>${message}</span>
        `;
        document.body.appendChild(toast);
        
        setTimeout(() => {
            toast.remove();
        }, 3000);
    }
    
    // Run auth check on page load
    checkAuth();
</script>

<style>
.toast {
    position: fixed;
    bottom: 20px;
    right: 20px;
    padding: 12px 20px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    gap: 10px;
    animation: slideInRight 0.3s ease;
    z-index: 9999;
    box-shadow: 0 10px 25px -5px rgba(0,0,0,0.1);
}

.toast-success {
    background: #10b981;
    color: white;
}

.toast-error {
    background: #ef4444;
    color: white;
}

@keyframes slideInRight {
    from {
        opacity: 0;
        transform: translateX(100px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}
</style>
</body>
</html>