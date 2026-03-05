import { createRouter, createWebHashHistory } from 'vue-router'

// Public Pages
import Hero from '../components/Hero.vue'
import BillResult from '../components/BillResult.vue'
import LoginPage from '../components/admin/LoginPage.vue'

// Admin Pages
import AdminLayout from '../components/admin/AdminLayout.vue'
import DashboardHome from '../components/admin/DashboardHome.vue'
import CustomerManagement from '../components/admin/CustomerManagement.vue'
import MeterInput from '../components/admin/MeterInput.vue'
import BillingData from '../components/admin/BillingData.vue'
import ReportPage from '../components/admin/ReportPage.vue'
import FinancialReportPage from '../components/admin/FinancialReportPage.vue'
import ContentManagement from '../components/admin/ContentManagement.vue'
import CetakStruk from '../components/admin/CetakStruk.vue'
import KeluhanManagement from '../components/admin/KeluhanManagement.vue'

// Petugas Pages
import PetugasLayout from '../components/petugas/PetugasLayout.vue'
import PetugasInputMeter from '../components/petugas/PetugasInputMeter.vue'

const routes = [
  // Public Routes
  {
    path: '/',
    name: 'Landing',
    component: () => import('../views/LandingPage.vue'),
    meta: { requiresAuth: false }
  },
  {
    path: '/login',
    name: 'Login',
    component: LoginPage,
    meta: { requiresAuth: false }
  },
  {
    path: '/bill-result',
    name: 'BillResult',
    component: BillResult,
    meta: { requiresAuth: false },
    props: true
  },
  {
    path: '/news',
    name: 'NewsArchive',
    component: () => import('../views/NewsArchive.vue'),
    meta: { requiresAuth: false }
  },
  {
    path: '/news/:id',
    name: 'NewsDetail',
    component: () => import('../views/NewsDetail.vue'),
    meta: { requiresAuth: false }
  },
  {
    path: '/gallery',
    name: 'GalleryArchive',
    component: () => import('../views/GalleryArchive.vue'),
    meta: { requiresAuth: false }
  },
  {
    path: '/profil',
    name: 'Profil',
    component: () => import('../views/ProfilPage.vue'),
    meta: { requiresAuth: false }
  },
  {
    path: '/lapor-keluhan',
    name: 'LaporKeluhan',
    component: () => import('../views/LaporKeluhan.vue'),
    meta: { requiresAuth: false }
  },
  {
    path: '/statistik',
    name: 'StatistikPublic',
    component: () => import('../views/StatistikPublic.vue'),
    meta: { requiresAuth: false }
  },

  // Admin Routes
  {
    path: '/admin',
    component: AdminLayout,
    meta: { requiresAuth: true, role: 'admin' },
    children: [
      {
        path: '',
        redirect: '/admin/dashboard'
      },
      {
        path: 'dashboard',
        name: 'AdminDashboard',
        component: DashboardHome
      },
      {
        path: 'customers',
        name: 'AdminCustomers',
        component: CustomerManagement
      },
      {
        path: 'meter',
        name: 'AdminMeter',
        component: MeterInput
      },
      {
        path: 'billing',
        name: 'AdminBilling',
        component: BillingData
      },
      {
        path: 'cetak-struk',
        name: 'AdminCetakStruk',
        component: CetakStruk
      },
      {
        path: 'reports',
        name: 'AdminReports',
        component: ReportPage
      },
      {
        path: 'financial',
        name: 'AdminFinancial',
        component: FinancialReportPage
      },
      {
        path: 'content',
        name: 'AdminContent',
        component: ContentManagement
      },
      {
        path: 'keluhan',
        name: 'AdminKeluhan',
        component: KeluhanManagement
      },
      {
        path: 'statistik-detail',
        name: 'AdminStatistikDetail',
        component: () => import('../components/admin/StatistikDetail.vue')
      }
    ]
  },

  // Petugas Routes
  {
    path: '/petugas',
    component: PetugasLayout,
    meta: { requiresAuth: true, role: 'petugas' },
    children: [
      {
        path: '',
        redirect: '/petugas/input-meter'
      },
      {
        path: 'input-meter',
        name: 'PetugasInputMeter',
        component: PetugasInputMeter,
        meta: {
          title: 'Input Meter',
          subtitle: 'Input data meteran air pelanggan'
        }
      }
    ]
  },

  // Catch all - 404
  {
    path: '/:pathMatch(.*)*',
    redirect: '/'
  }
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
  scrollBehavior(to, from, savedPosition) {
    if (savedPosition) {
      return savedPosition
    } else {
      return { top: 0 }
    }
  }
})

// Navigation Guards
router.beforeEach((to, from, next) => {
  const authToken = localStorage.getItem('auth_token')
  const userRole = localStorage.getItem('user_role')

  // Allow access to public routes
  if (!to.meta.requiresAuth) {
    return next()
  }

  // Check if user is authenticated
  if (!authToken) {
    return next({
      path: '/login',
      query: { redirect: to.fullPath }
    })
  }

  // Check role-based access
  const requiredRole = to.meta.role

  if (requiredRole) {
    // Admin can access everything (super user)
    if (userRole === 'admin') {
      return next()
    }

    // Petugas trying to access admin routes - block
    if (requiredRole === 'admin' && userRole === 'petugas') {
      // Show toast notification
      if (window.showToast) {
        window.showToast('Anda tidak memiliki akses ke halaman ini', 'error')
      }
      return next('/petugas/input-meter')
    }

    // Petugas accessing their own routes
    if (requiredRole === 'petugas' && userRole === 'petugas') {
      return next()
    }

    // Role doesn't match - redirect to appropriate dashboard
    if (userRole === 'admin') {
      return next('/admin/dashboard')
    } else if (userRole === 'petugas') {
      return next('/petugas/input-meter')
    }
  }

  // Default - allow
  next()
})

export default router
