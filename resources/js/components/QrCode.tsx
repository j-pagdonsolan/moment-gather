import { QRCodeCanvas } from 'qrcode.react';
import { useRef } from 'react';
import { Copy, Download, ExternalLink } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';

interface QrCodeProps {
    /** The absolute public URL to encode. */
    url: string;
    /** The download filename, e.g. "momentgather-summer-party-qr.png". */
    fileName: string;
    /** Optional CSS class for the visible QR container. */
    className?: string;
}

export default function QrCode({ url, fileName, className }: QrCodeProps) {
    const containerRef = useRef<HTMLDivElement>(null);

    const handleDownload = () => {
        const canvas = containerRef.current?.querySelector('canvas');
        if (!canvas) {
            // Canvas not yet mounted — guard against a null/no-op download.
            return;
        }
        const dataUrl = canvas.toDataURL('image/png');
        const link = document.createElement('a');
        link.href = dataUrl;
        link.download = fileName;
        link.click();
    };

    const handleCopy = async () => {
        try {
            if (!navigator.clipboard) {
                throw new Error('Clipboard API unavailable');
            }
            await navigator.clipboard.writeText(url);
            toast.success('Link copied!');
        } catch {
            toast.error('Could not copy the link. Please copy it manually.');
        }
    };

    return (
        <div className="flex flex-col items-start gap-4">
            {/* One high-res canvas, displayed small via CSS, downloaded at full size. */}
            <div ref={containerRef} className={className ?? 'w-full max-w-[200px]'}>
                <QRCodeCanvas
                    value={url}
                    size={1024}
                    marginSize={4}
                    bgColor="#ffffff"
                    fgColor="#000000"
                    level="M"
                    style={{ width: '100%', height: 'auto', maxWidth: '200px' }}
                />
            </div>

            <div className="flex flex-wrap gap-2">
                <Button onClick={handleDownload}>
                    <Download className="mr-2 size-4" />
                    Download QR Code
                </Button>
                <Button variant="outline" onClick={handleCopy}>
                    <Copy className="mr-2 size-4" />
                    Copy Link
                </Button>
                <Button asChild variant="outline">
                    <a href={url} target="_blank" rel="noopener noreferrer">
                        <ExternalLink className="mr-2 size-4" />
                        Open Event
                    </a>
                </Button>
            </div>
        </div>
    );
}
