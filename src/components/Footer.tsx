'use client';

import React from 'react';
import Link from 'next/link';
import { Mail, Phone, MessageSquare } from 'lucide-react';

export default function Footer() {
  const currentYear = new Date().getFullYear();

  return (
    <footer className="footer-premium w-full py-16 mt-auto">
      <div className="container max-w-7xl mx-auto px-4">
        <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-12 mb-12">
          {/* Brand Section */}
          <div className="space-y-4">
            <Link href="/" className="inline-flex items-center gap-3 group">
              <div className="bg-white-logo p-1.5 rounded-lg flex items-center justify-center shadow-md">
                <img
                  src="/logo-small.png"
                  alt="Empower Students Logo"
                  className="h-8 w-8 object-contain"
                />
              </div>
              <span className="font-extrabold text-lg text-white group-hover:text-indigo-400 transition-colors">
                Empower Students
              </span>
            </Link>
            <p className="text-xs text-slate-400 leading-relaxed max-w-xs pt-2">
              A multidisciplinary developmental assessment platform for children. Empowering minds, shaping tomorrows.
            </p>
          </div>

          {/* Quick Links */}
          <div>
            <h4 className="text-white font-bold text-xs uppercase tracking-wider mb-5">Quick Links</h4>
            <ul className="space-y-3 text-sm">
              <li>
                <Link href="/" className="text-slate-400 hover:text-white transition-colors">
                  Home
                </Link>
              </li>
              <li>
                <Link href="/specialists" className="text-slate-400 hover:text-white transition-colors">
                  Our Panel
                </Link>
              </li>
              <li>
                <Link href="/about" className="text-slate-400 hover:text-white transition-colors">
                  About Us
                </Link>
              </li>
              <li>
                <Link href="/login" className="text-slate-400 hover:text-white transition-colors">
                  Parent Login
                </Link>
              </li>
            </ul>
          </div>

          {/* Legal Policies */}
          <div>
            <h4 className="text-white font-bold text-xs uppercase tracking-wider mb-5">Policies</h4>
            <ul className="space-y-3 text-sm">
              <li>
                <Link href="/privacy" className="text-slate-400 hover:text-white transition-colors">
                  Privacy Policy
                </Link>
              </li>
              <li>
                <Link href="/terms" className="text-slate-400 hover:text-white transition-colors">
                  Terms and Conditions
                </Link>
              </li>
              <li>
                <Link href="/refund" className="text-slate-400 hover:text-white transition-colors">
                  Refund Policy
                </Link>
              </li>
            </ul>
          </div>

          {/* Contact Info */}
          <div>
            <h4 className="text-white font-bold text-xs uppercase tracking-wider mb-5">Get in Touch</h4>
            <ul className="space-y-3 text-sm font-normal">
              <li className="flex items-center gap-2">
                <Phone size={14} className="text-indigo-accent shrink-0" />
                <a href="tel:+919311883132" className="text-slate-400 hover:text-white transition-colors">
                  +91 9311883132
                </a>
              </li>
              <li className="flex items-center gap-2">
                <MessageSquare size={14} className="text-indigo-accent shrink-0" />
                <a
                  href="https://wa.me/919311883132"
                  className="text-slate-400 hover:text-white transition-colors"
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  +91 9311883132 (WhatsApp)
                </a>
              </li>
              <li className="flex items-center gap-2">
                <Mail size={14} className="text-indigo-accent shrink-0" />
                <a href="mailto:support@empowerstudents.in" className="text-slate-400 hover:text-white transition-colors">
                  support@empowerstudents.in
                </a>
              </li>
            </ul>
          </div>
        </div>

        {/* Copyright Footer */}
        <div className="border-t border-slate-900 pt-8 text-center text-xs text-slate-500">
          &copy; {currentYear} Empower Students. All rights reserved.
        </div>
      </div>
    </footer>
  );
}
